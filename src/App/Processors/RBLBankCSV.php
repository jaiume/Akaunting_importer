<?php

namespace App\Processors;

/**
 * RBL Bank CSV Import Processor
 * Parses Republic Bank Limited CSV transaction history exports
 * 
 * Expected CSV format:
 * - Header section with account info (first few lines)
 * - "History," marker line
 * - Column headers: Transaction Date,Description,Debit TTD,Credit TTD,Running Balance TTD,
 * - Transaction rows with DD/MM/YYYY date format
 * - Debits are negative values, Credits are positive values
 */
class RBLBankCSV extends BaseProcessor
{
    protected $currency = 'TTD';
    protected $accountNumber;
    protected $dateRange;

    public function validate(): bool
    {
        if (!file_exists($this->filePath)) {
            throw new \Exception("File not found: {$this->filePath}");
        }

        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new \Exception("Invalid file type. Expected CSV file.");
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
        
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new \Exception("Could not open CSV file");
        }

        $inTransactionSection = false;
        $headerRow = null;
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            // Extract account number from header section
            if (preg_match('/^Account Number,Nickname,/i', $line)) {
                // Next line has the account info
                $nextLine = fgets($handle);
                if ($nextLine) {
                    $parts = str_getcsv(trim($nextLine));
                    if (isset($parts[1])) {
                        $this->accountNumber = $parts[1];
                    }
                    if (isset($parts[4])) {
                        $this->dateRange = $parts[4];
                    }
                }
                continue;
            }
            
            // Detect "History," marker
            if (preg_match('/^History,?$/i', $line)) {
                $inTransactionSection = true;
                continue;
            }
            
            // Detect transaction header row
            if ($inTransactionSection && preg_match('/^Transaction Date,Description,/i', $line)) {
                $headerRow = str_getcsv($line);
                continue;
            }
            
            // Parse transaction rows
            if ($inTransactionSection && $headerRow !== null) {
                $row = str_getcsv($line);
                
                // Skip if not enough columns
                if (count($row) < 4) {
                    continue;
                }
                
                $parsed = $this->parseTransactionRow($row);
                if ($parsed) {
                    $transactions[] = $parsed;
                }
            }
        }
        
        fclose($handle);
        
        return $transactions;
    }

    /**
     * Parse a single transaction row
     * Expected columns: Transaction Date, Description, Debit TTD, Credit TTD, Running Balance TTD
     */
    protected function parseTransactionRow(array $row): ?array
    {
        // Column indices
        $dateCol = 0;
        $descCol = 1;
        $debitCol = 2;
        $creditCol = 3;
        
        // Get values
        $dateStr = trim($row[$dateCol] ?? '');
        $description = trim($row[$descCol] ?? '');
        $debitStr = trim($row[$debitCol] ?? '');
        $creditStr = trim($row[$creditCol] ?? '');
        
        // Skip if no date
        if (empty($dateStr)) {
            return null;
        }
        
        // Parse date (DD/MM/YYYY format)
        $dateParts = explode('/', $dateStr);
        if (count($dateParts) !== 3) {
            return null;
        }
        
        $day = (int)$dateParts[0];
        $month = (int)$dateParts[1];
        $year = (int)$dateParts[2];
        
        // Validate date parts
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 2000) {
            return null;
        }
        
        $transactionDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
        // Parse amount - either debit (negative) or credit (positive)
        $amount = 0;
        $transactionType = null;
        
        if (!empty($debitStr)) {
            // Debit: remove commas and parse
            $debitStr = str_replace([',', ' '], '', $debitStr);
            $amount = floatval($debitStr); // Already negative in the CSV
            $transactionType = 'debit';
        } elseif (!empty($creditStr)) {
            // Credit: remove commas and parse
            $creditStr = str_replace([',', ' '], '', $creditStr);
            $amount = floatval($creditStr); // Positive value
            $transactionType = 'credit';
        }
        
        // Skip zero amount transactions
        if ($amount == 0) {
            return null;
        }
        
        // Extract bank reference if present in description
        $bankRef = $this->extractBankRef($description);
        
        return [
            'transaction_date' => $transactionDate,
            'bank_ref' => $bankRef,
            'description' => $description,
            'transaction_currency' => $this->currency,
            'transaction_amount' => $amount,
            'transaction_type' => $transactionType,
        ];
    }

    /**
     * Extract bank reference from description if present
     */
    protected function extractBankRef(string $description): ?string
    {
        // Look for common reference patterns
        // IB TRANSFER, POS-, ABM- etc. don't typically have refs
        // Could be extended for specific patterns
        return null;
    }
}
