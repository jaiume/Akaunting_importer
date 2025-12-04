<?php

namespace App\Processors;

use Smalot\PdfParser\Parser;

/**
 * RBL Credit Card PDF Import Processor
 * Parses Republic Bank Limited Credit Card statement PDFs
 * 
 * Credit cards have both TTD and USD sections - imports based on account currency setting
 * 
 * For credit cards:
 * - Positive amounts = Expenses (charges)
 * - Negative amounts = Income (payments/refunds)
 */
class RBLCreditCardPDF extends BaseProcessor
{
    protected $statementYear;
    protected $statementMonth;
    protected $accountNumber;
    protected $targetCurrency; // 'TTD' or 'USD' - set from account settings

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

    /**
     * Set the target currency based on the account's currency setting
     */
    public function setTargetCurrency(string $currency): void
    {
        $this->targetCurrency = strtoupper($currency);
    }

    public function process(): array
    {
        // Check if batch can be processed (pending or failed status only)
        if (!$this->canProcess()) {
            throw new \Exception("Batch cannot be processed. It may already be completed or currently processing.");
        }
        
        $this->validate();
        
        // Get target currency from batch's account
        $this->loadTargetCurrency();
        
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

    /**
     * Load the target currency from the batch's account settings
     */
    protected function loadTargetCurrency(): void
    {
        $stmt = $this->db->prepare("
            SELECT a.currency 
            FROM import_batches b
            JOIN accounts a ON b.account_id = a.account_id
            WHERE b.batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $this->batchId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->targetCurrency = $result['currency'] ?? 'TTD';
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
        
        // Extract statement date to determine year
        $this->extractStatementPeriod($fullText);
        
        // Extract account number
        $this->extractAccountNumber($fullText);
        
        // Parse transactions based on target currency
        $transactions = $this->parseTransactionsBySection($fullText);
        
        return $transactions;
    }

    /**
     * Extract text using pdftotext command-line tool
     */
    protected function extractTextWithPdftotext(): ?string
    {
        $pdftotextPath = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
        
        if (empty($pdftotextPath)) {
            return null;
        }
        
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
            return $pdf->getText();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract statement period from PDF text
     * 
     * The Payment Due Date is always in the month FOLLOWING the last transaction.
     * So we extract that date and derive the statement month/year from it.
     * Format: "PAYMENT DUE DATE" followed by DD/MM/YY
     */
    protected function extractStatementPeriod(string $text): void
    {
        // Best method: Find Payment Due Date and derive statement period
        // Format: "PAYMENT DUE DATE" followed by DD/MM/YY (e.g., "10/12/25" = Dec 10, 2025)
        // Use [\s\S] to match across newlines
        if (preg_match('/PAYMENT\s+DUE\s+DATE[\s\S]{0,20}?(\d{1,2})\/(\d{1,2})\/(\d{2,4})/i', $text, $matches)) {
            $dueDay = (int)$matches[1];
            $dueMonth = (int)$matches[2];
            $dueYear = (int)$matches[3];
            
            // Handle 2-digit year
            if ($dueYear < 100) {
                $dueYear += 2000;
            }
            
            // Statement month is one month BEFORE the payment due month
            $this->statementMonth = $dueMonth - 1;
            $this->statementYear = $dueYear;
            
            // Handle January payment due date → statement is December of previous year
            if ($this->statementMonth < 1) {
                $this->statementMonth = 12;
                $this->statementYear--;
            }
            
            error_log("RBLCreditCardPDF: Found Payment Due Date {$dueDay}/{$dueMonth}/{$dueYear} → Statement: {$this->statementMonth}/{$this->statementYear}");
            return;
        }
        
        // Fallback: Try to find month name + year (e.g., "NOVEMBER 2025")
        $monthNames = [
            'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4,
            'MAY' => 5, 'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8,
            'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12
        ];
        
        if (preg_match('/(JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)\s+(\d{4})/i', $text, $matches)) {
            $this->statementMonth = $monthNames[strtoupper($matches[1])] ?? (int)date('m');
            $this->statementYear = (int)$matches[2];
            error_log("RBLCreditCardPDF: Found month name {$matches[1]} {$matches[2]} → Statement: {$this->statementMonth}/{$this->statementYear}");
            return;
        }
        
        // Last resort: Use first DD/MM/YY date found (interpret as payment due date)
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $text, $matches)) {
            $dueDay = (int)$matches[1];
            $dueMonth = (int)$matches[2];
            $dueYear = (int)$matches[3];
            
            if ($dueYear < 100) {
                $dueYear += 2000;
            }
            
            // Treat this as payment due date, so statement is one month before
            $this->statementMonth = $dueMonth - 1;
            $this->statementYear = $dueYear;
            
            if ($this->statementMonth < 1) {
                $this->statementMonth = 12;
                $this->statementYear--;
            }
            
            error_log("RBLCreditCardPDF: Fallback DD/MM/YY date {$matches[0]} → dueMonth={$dueMonth}, Statement: {$this->statementMonth}/{$this->statementYear}");
            return;
        }
        
        // Default to current date
        $this->statementYear = (int)date('Y');
        $this->statementMonth = (int)date('m');
        error_log("RBLCreditCardPDF: Using current date as fallback → Statement: {$this->statementMonth}/{$this->statementYear}");
    }

    /**
     * Extract account number from PDF text
     */
    protected function extractAccountNumber(string $text): void
    {
        if (preg_match('/ACCOUNT\s*NUMBER[:\s]*(\d{16})/i', $text, $matches)) {
            $this->accountNumber = $matches[1];
        } elseif (preg_match('/(\d{16})/', $text, $matches)) {
            $this->accountNumber = $matches[1];
        }
    }

    /**
     * Parse transactions filtering by currency prefix in amount column
     * TTD transactions have "TT$" prefix, USD transactions have "US$" prefix
     * Credits are marked with "CR" suffix
     */
    protected function parseTransactionsBySection(string $text): array
    {
        $transactions = [];
        $lines = preg_split('/\r?\n/', $text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Try to parse transaction line - it will filter by currency
            $parsed = $this->parseTransactionLine($line);
            if ($parsed) {
                $transactions[] = $parsed;
            }
        }
        
        return $transactions;
    }

    /**
     * Parse a single transaction line
     * Currency is determined by TT$ or US$ prefix in the amount
     * Credits are marked with CR suffix (e.g., "1,865.08CR")
     */
    protected function parseTransactionLine(string $line): ?array
    {
        // Skip header lines and non-transaction content
        if (preg_match('/^(ACCOUNT|PREVIOUS|PURCHASES|CASH|MISC|FEES|FINANCE|PAYMENTS|RETURNS|NEW|CREDIT\s+LIMIT|PAGE|STATEMENT|REPUBLIC|VISA|AMOUNT|BALANCE|MINIMUM|DATE|DESCRIPTION)/i', $line)) {
            return null;
        }
        
        // Detect currency from the line - must have TT$ or US$ 
        $currencyMatch = null;
        if (preg_match('/TT\$/', $line)) {
            $currencyMatch = 'TTD';
        } elseif (preg_match('/US\$/', $line)) {
            $currencyMatch = 'USD';
        }
        
        // Skip if currency doesn't match target
        if ($currencyMatch === null) {
            return null; // No currency indicator found
        }
        
        if ($currencyMatch !== $this->targetCurrency) {
            return null; // Currency doesn't match account
        }
        
        // Pattern: Line contains date (MM/DD), description parts, and amount with currency
        // Amount format: TT$ 1,865.08CR or US$ 118.35CR or TT$ 450.00
        
        // Extract the amount with currency prefix - use the LAST match on the line
        // because descriptions may contain currency amounts (e.g., "Opt Issuer Fee - TT$53.99")
        // but the actual transaction amount is always at the end of the line
        if (!preg_match_all('/(TT\$|US\$)\s*([\d,]+\.\d{2})(CR)?/i', $line, $amountMatches, PREG_SET_ORDER)) {
            return null;
        }
        
        // Use the LAST match (rightmost amount is the actual transaction amount)
        $lastMatch = end($amountMatches);
        $amount = str_replace(',', '', $lastMatch[2]);
        $isCredit = !empty($lastMatch[3]); // CR suffix means credit
        
        // Try to extract the Trans. Date (Finance Date) - the SECOND MM/DD pattern on the line
        // Line format: "PostingDate Reference TransDate Description Amount"
        // e.g., "10/21 1251021096311914194 10/21 RepublicOnline Credit Card Transaction TT$ 343.44CR"
        // We want the second date (Trans. Date), not the first (Posting Date)
        if (!preg_match_all('/(\d{2})\/(\d{2})/', $line, $dateMatches, PREG_SET_ORDER)) {
            return null;
        }
        
        // Use the second date if available (Trans. Date), otherwise use the first
        $dateIndex = count($dateMatches) >= 2 ? 1 : 0;
        $month = $dateMatches[$dateIndex][1];
        $day = $dateMatches[$dateIndex][2];
        
        // Extract reference number if present (long numeric string)
        $reference = null;
        if (preg_match('/(\d{10,})/', $line, $refMatches)) {
            $reference = $refMatches[1];
        }
        
        // Extract description - everything between date/ref and the final currency amount
        // Remove the date, reference, and ONLY the last amount (actual transaction amount)
        $description = $line;
        $description = preg_replace('/^\d{2}\/\d{2}\s*/', '', $description); // Remove leading date
        $description = preg_replace('/\d{10,}\s*/', '', $description); // Remove reference numbers
        $description = preg_replace('/\d{2}\/\d{2}\s*/', '', $description); // Remove finance dates
        
        // Only remove the LAST currency amount (the actual transaction amount)
        // This preserves amounts that are part of the description (e.g., "Opt Issuer Fee - TT$53.99")
        $lastAmountPattern = '/(TT\$|US\$)\s*' . preg_quote($lastMatch[2], '/') . '(CR)?\s*$/i';
        $description = preg_replace($lastAmountPattern, '', $description);
        $description = trim($description);
        
        // Clean up description
        $description = preg_replace('/\s+/', ' ', $description);
        
        if (empty($description)) {
            $description = 'Transaction';
        }
        
        return $this->buildTransaction($month, $day, $reference, $description, $amount, $isCredit);
    }

    /**
     * Build transaction array from parsed components
     */
    protected function buildTransaction(
        string $month, 
        string $day, 
        ?string $reference, 
        string $description, 
        string $amount, 
        bool $isCredit = false
    ): ?array {
        // Validate date
        $txnMonth = (int)$month;
        $txnDay = (int)$day;
        
        if ($txnMonth < 1 || $txnMonth > 12 || $txnDay < 1 || $txnDay > 31) {
            return null;
        }
        
        // Determine year based on statement period
        // Statement period is derived from Payment Due Date (which is in the following month)
        // So if statement is November 2025, transactions are from Nov 2025 or earlier months
        // 
        // Logic: If transaction month > statement month, it crossed a year boundary
        // and belongs to the previous year
        //
        // Example: Statement Nov 2025 (month 11)
        //   - Transaction month 11 (Nov) → 2025 (11 <= 11)
        //   - Transaction month 10 (Oct) → 2025 (10 <= 11)
        //   - Transaction month 12 (Dec) → 2024 (12 > 11, previous year)
        $txnYear = $this->statementYear;
        if ($txnMonth > $this->statementMonth) {
            $txnYear--;
        }
        
        // Debug: Log first few transactions to verify date logic
        static $debugCount = 0;
        if ($debugCount < 5) {
            error_log("RBLCreditCardPDF buildTransaction: txnMonth={$txnMonth}, statementMonth={$this->statementMonth}, statementYear={$this->statementYear} → txnYear={$txnYear}, date={$txnYear}-{$txnMonth}-{$txnDay}");
            $debugCount++;
        }
        
        $transactionDate = sprintf('%04d-%02d-%02d', $txnYear, $txnMonth, $txnDay);
        
        // Parse amount
        $amountValue = floatval(str_replace(',', '', $amount));
        
        if ($amountValue == 0) {
            return null;
        }
        
        // For credit cards: positive = expense (charge), negative = credit (payment/refund)
        // Credits in the statement are marked, charges are not
        if ($isCredit) {
            $amountValue = -$amountValue; // Payments/refunds become negative (income for CC)
            $transactionType = 'credit';
        } else {
            // Charges stay positive (expense for CC)
            $transactionType = 'debit';
        }
        
        return [
            'transaction_date' => $transactionDate,
            'bank_ref' => $reference,
            'description' => trim($description),
            'transaction_currency' => $this->targetCurrency,
            'transaction_amount' => $amountValue,
            'transaction_type' => $transactionType,
        ];
    }
}
