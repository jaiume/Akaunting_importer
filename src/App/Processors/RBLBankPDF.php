<?php

namespace App\Processors;

use Smalot\PdfParser\Parser;

/**
 * RBL Bank PDF Import Processor
 * Parses Republic Bank Limited periodic statement PDFs
 * 
 * Expected format:
 * - Account #: CHQ-XXXXXXXXXX
 * - Currency: TTD
 * - Transaction table: Date | Cheque # | Description | Amount | Balance
 * - Date format: DD/MM (year from statement period)
 * - Debits marked with "-" suffix
 * - Negative balances in parentheses
 */
class RBLBankPDF extends BaseProcessor
{
    protected $statementYear;
    protected $statementMonth;
    protected $accountNumber;
    protected $currency = 'TTD';

    public function validate(): bool
    {
        if (!file_exists($this->filePath)) {
            throw new \Exception("File not found: {$this->filePath}");
        }

        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new \Exception("Invalid file type. Expected PDF file.");
        }

        return true;
    }

    public function process(): array
    {
        // Check if batch can be processed (pending or failed status only)
        if (!$this->canProcess()) {
            throw new \Exception("Batch cannot be processed. It may already be completed or currently processing.");
        }
        
        $this->validate();
        
        $this->updateBatchStatus('processing');
        
        try {
            $transactions = $this->parseTransactions();
            $this->saveTransactions($transactions);
            $this->updateBatchStatus('completed');
            return $transactions;
        } catch (\Exception $e) {
            $this->updateBatchStatus('failed', $e->getMessage());
            throw $e;
        }
    }

    protected function parseTransactions(): array
    {
        $transactions = [];
        
        // Try to extract text using pdftotext first (better handling of complex PDFs)
        $fullText = $this->extractTextWithPdftotext();
        
        if (empty($fullText)) {
            // Fall back to PHP parser
            $fullText = $this->extractTextWithPhpParser();
        }
        
        if (empty($fullText)) {
            throw new \Exception("Failed to extract text from PDF");
        }
        
        // Extract statement period to determine year
        $this->extractStatementPeriod($fullText);
        
        // Extract account number
        $this->extractAccountNumber($fullText);
        
        // Parse all transactions from the full text
        $transactions = $this->parsePageTransactions($fullText);
        
        return $transactions;
    }
    
    /**
     * Extract text using pdftotext command-line tool (from poppler-utils)
     * This handles complex PDFs much better than PHP parsers
     */
    protected function extractTextWithPdftotext(): ?string
    {
        // Check if pdftotext is available
        $pdftotextPath = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
        
        if (empty($pdftotextPath)) {
            return null;
        }
        
        // Use pdftotext with layout preservation
        $escapedPath = escapeshellarg($this->filePath);
        $command = "{$pdftotextPath} -layout {$escapedPath} -";
        
        $output = shell_exec($command . ' 2>/dev/null');
        
        if (!empty($output) && strlen($output) > 100) {
            return $output;
        }
        
        return null;
    }
    
    /**
     * Extract text using PHP PDF parser (fallback)
     */
    protected function extractTextWithPhpParser(): ?string
    {
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $parser = new Parser([], $config);
            
            $pdf = $parser->parseFile($this->filePath);
            
            // Get text from all pages
            $fullText = '';
            $pages = $pdf->getPages();
            
            foreach ($pages as $page) {
                try {
                    $pageText = $page->getText();
                    $fullText .= $pageText . "\n";
                } catch (\Exception $e) {
                    // Skip problematic pages but continue
                    continue;
                }
            }
            
            // If page-by-page failed, try getting full document text
            if (empty(trim($fullText))) {
                $fullText = $pdf->getText();
            }
            
            return $fullText;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Parse transactions from a single page
     * Handles multi-line transactions where description spans multiple lines
     */
    protected function parsePageTransactions(string $pageText): array
    {
        $transactions = [];
        
        // Split text into lines and clean up
        $lines = preg_split('/\r?\n/', $pageText);
        
        $inTransactionSection = false;
        $pendingLine = null; // Stores first line of multi-line transaction
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            // Detect transaction section markers - these indicate transactions follow
            // Matches: "TRANSACTION INFORMATION", "TRANSACTION INFORMATION (continued)"
            if (preg_match('/TRANSACTION INFORMATION/i', $line)) {
                $inTransactionSection = true;
                continue;
            }
            
            // Detect transaction section start (header row) - multiple formats
            // Format 1: "Date Cheque # Description Amount Balance"
            // Format 2: "| Date | Cheque # | Description | Amount | Balance |" (table format)
            if (preg_match('/^[\|\s]*Date\s*[\|\s]+Cheque/i', $line)) {
                $inTransactionSection = true;
                continue;
            }
            
            // Skip page footers/headers but keep parsing
            if (preg_match('/^Page:|^SEE OVERLEAF|^Head Office|republictt\.com/i', $line)) {
                continue;
            }
            
            // Skip summary lines but don't stop - more transactions may follow on this page
            if (preg_match('/^ACCOUNT SUMMARY|^Beginning Balance|^Ending Balance|^Total Debits|^Total Credits/i', $line)) {
                continue;
            }
            
            // Skip table separators (markdown format)
            if (preg_match('/^\|[\s\-\|]+\|$/', $line)) {
                continue;
            }
            
            if ($inTransactionSection) {
                // Clean up markdown table formatting if present
                $cleanLine = preg_replace('/^\||\|$/', '', $line);  // Remove leading/trailing pipes
                $cleanLine = preg_replace('/\s*\|\s*/', ' ', $cleanLine);  // Replace pipes with spaces
                $cleanLine = trim($cleanLine);
                
                if (empty($cleanLine)) {
                    continue;
                }
                
                // Check if line starts with a date (DD/MM)
                $startsWithDate = preg_match('/^(\d{2})\/(\d{2})\s*/', $cleanLine);
                
                // Check if line has amount pattern at end (indicates complete transaction line)
                $hasAmount = preg_match('/\s+([\d,]+\.?\d*)\s*(-?)\s+([\d,]+\.?\d*|\([\d,]+\.?\d*\))$/', $cleanLine);
                
                if ($startsWithDate && $hasAmount) {
                    // Complete single-line transaction
                    // First, process any pending multi-line transaction
                    if ($pendingLine !== null) {
                        $pendingLine = null;
                    }
                    
                    $parsed = $this->parseTransactionLine($cleanLine);
                    if ($parsed) {
                        $transactions[] = $parsed;
                    }
                } elseif ($startsWithDate && !$hasAmount) {
                    // First line of a multi-line transaction (has date but no amount)
                    $pendingLine = $cleanLine;
                } elseif (!$startsWithDate && $pendingLine !== null) {
                    // Continuation line - append to pending line
                    // Check if this continuation line has the amount
                    if ($hasAmount) {
                        // Complete the multi-line transaction
                        $combinedLine = $pendingLine . ' ' . $cleanLine;
                        $parsed = $this->parseTransactionLine($combinedLine);
                        if ($parsed) {
                            $transactions[] = $parsed;
                        }
                        $pendingLine = null;
                    } else {
                        // More description text, keep appending
                        $pendingLine .= ' ' . $cleanLine;
                    }
                }
            }
        }
        
        return $transactions;
    }
    
    /**
     * Extract statement period from PDF text
     */
    protected function extractStatementPeriod(string $text): void
    {
        // Look for "Period: DD/MM/YYYY to DD/MM/YYYY"
        if (preg_match('/Period:\s*(\d{2})\/(\d{2})\/(\d{4})\s*to\s*(\d{2})\/(\d{2})\/(\d{4})/i', $text, $matches)) {
            $this->statementMonth = (int)$matches[5]; // End month
            $this->statementYear = (int)$matches[6];  // End year
        } elseif (preg_match('/Date:\s*(\d{2})\/(\d{2})\/(\d{4})/i', $text, $matches)) {
            // Fallback to statement date
            $this->statementMonth = (int)$matches[2];
            $this->statementYear = (int)$matches[3];
        } else {
            // Default to current year
            $this->statementYear = (int)date('Y');
            $this->statementMonth = (int)date('m');
        }
    }
    
    /**
     * Extract account number from PDF text
     */
    protected function extractAccountNumber(string $text): void
    {
        // Look for "ACCOUNT #: CHQ - XXXXXXXXXXXX" or similar
        if (preg_match('/ACCOUNT\s*#?:?\s*(CHQ\s*-?\s*\d+)/i', $text, $matches)) {
            $this->accountNumber = preg_replace('/\s+/', '', $matches[1]);
        }
    }
    
    /**
     * Parse a single transaction line
     * Format: DD/MM [Cheque#] Description Amount[-] Balance
     */
    protected function parseTransactionLine(string $line): ?array
    {
        // Match date at the beginning (DD/MM format)
        if (!preg_match('/^(\d{2})\/(\d{2})\s+(.+)/', $line, $matches)) {
            return null;
        }
        
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $remainder = trim($matches[3]);
        
        // Validate day and month
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }
        
        // Determine the year - handle year rollover for statements crossing year boundary
        $txnMonth = $month;
        $txnYear = $this->statementYear;
        
        // If transaction month > statement month, it's from previous year
        if ($txnMonth > $this->statementMonth) {
            $txnYear--;
        }
        
        $transactionDate = sprintf('%04d-%02d-%02d', $txnYear, $txnMonth, $day);
        
        // Try to extract amount and balance from end of line
        // Pattern: Amount (with optional comma, decimal) optionally followed by " -" for debits, then Balance
        // Examples:
        // "300.11 - 576.84"  (debit)
        // "847.67 870.51"    (credit)
        // "20.00 - 1,893.22" (debit with comma in balance)
        
        $amountPattern = '/\s+([\d,]+\.?\d*)\s*(-?)\s+([\d,]+\.?\d*|\([\d,]+\.?\d*\))$/';
        
        if (!preg_match($amountPattern, $remainder, $amountMatches)) {
            return null;
        }
        
        $amountStr = str_replace(',', '', $amountMatches[1]);
        $isDebit = trim($amountMatches[2]) === '-';
        // Note: $amountMatches[3] contains the balance which we ignore
        
        // Get description (everything between date and amount)
        $descriptionEnd = strrpos($remainder, $amountMatches[0]);
        if ($descriptionEnd === false) {
            $descriptionEnd = strlen($remainder);
        }
        $description = trim(substr($remainder, 0, $descriptionEnd));
        
        // Check for cheque number at start of description
        $chequeNumber = null;
        if (preg_match('/^(\d{4,})\s+(.+)/', $description, $chequeMatch)) {
            $chequeNumber = $chequeMatch[1];
            $description = $chequeMatch[2];
        }
        
        // Parse amount
        $amount = floatval($amountStr);
        if ($amount == 0) {
            return null; // Skip zero amount transactions
        }
        
        // Determine transaction type
        $transactionType = $isDebit ? 'debit' : 'credit';
        
        return [
            'transaction_date' => $transactionDate,
            'bank_ref' => $chequeNumber,
            'description' => trim($description),
            'transaction_currency' => $this->currency,
            'transaction_amount' => $isDebit ? -$amount : $amount,
            'transaction_type' => $transactionType,
        ];
    }
}
