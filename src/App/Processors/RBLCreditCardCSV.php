<?php

namespace App\Processors;

/**
 * RBL Credit Card CSV Import Processor
 * Parses Republic Bank Limited Credit Card CSV transaction history exports
 * 
 * CSV has separate columns for TTD and USD amounts:
 * - Amount in TTD: Non-zero for TTD transactions
 * - Amount in USD: Non-zero for USD transactions
 * 
 * For credit cards:
 * - Positive amounts = Expenses (charges)
 * - Negative amounts = Income (payments/refunds)
 */
class RBLCreditCardCSV extends BaseProcessor
{
    protected $targetCurrency;
    protected $cardNumber;

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
        
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new \Exception("Could not open CSV file");
        }

        $inTransactionSection = false;
        $headerRow = null;
        $columnIndices = [];
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            // Extract card number from header section
            if (preg_match('/^Credit Card Number,Nickname,/i', $line)) {
                // Next line has the card info
                $nextLine = fgets($handle);
                if ($nextLine) {
                    $parts = str_getcsv(trim($nextLine));
                    if (isset($parts[0])) {
                        $this->cardNumber = $parts[0];
                    }
                }
                continue;
            }
            
            // Detect "Transactions," marker
            if (preg_match('/^Transactions,?$/i', $line)) {
                $inTransactionSection = true;
                continue;
            }
            
            // Detect transaction header row
            if ($inTransactionSection && preg_match('/^Date,Card Number,Description,/i', $line)) {
                $headerRow = str_getcsv($line);
                
                // Find column indices
                foreach ($headerRow as $index => $header) {
                    $header = trim(strtolower($header));
                    if ($header === 'date') {
                        $columnIndices['date'] = $index;
                    } elseif ($header === 'description') {
                        $columnIndices['description'] = $index;
                    } elseif ($header === 'original amount') {
                        $columnIndices['original_amount'] = $index;
                    } elseif ($header === 'amount in ttd') {
                        $columnIndices['ttd_amount'] = $index;
                    } elseif ($header === 'amount in usd') {
                        $columnIndices['usd_amount'] = $index;
                    }
                }
                continue;
            }
            
            // Parse transaction rows
            if ($inTransactionSection && $headerRow !== null) {
                $row = str_getcsv($line);
                
                // Skip if not enough columns
                if (count($row) < 5) {
                    continue;
                }
                
                $parsed = $this->parseTransactionRow($row, $columnIndices);
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
     * Columns: Date, Card Number, Description, Original Amount, Amount in TTD, Amount in USD
     */
    protected function parseTransactionRow(array $row, array $columnIndices): ?array
    {
        // Get values using column indices
        $dateStr = trim($row[$columnIndices['date'] ?? 0] ?? '');
        $description = trim($row[$columnIndices['description'] ?? 2] ?? '');
        $originalAmount = trim($row[$columnIndices['original_amount'] ?? 3] ?? '');
        $ttdAmountStr = trim($row[$columnIndices['ttd_amount'] ?? 4] ?? '');
        $usdAmountStr = trim($row[$columnIndices['usd_amount'] ?? 5] ?? '');
        
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
        
        // Parse amount based on target currency
        $amount = 0;
        
        if ($this->targetCurrency === 'TTD') {
            // Use TTD amount column
            $ttdAmountStr = str_replace([',', ' ', '"'], '', $ttdAmountStr);
            $amount = floatval($ttdAmountStr);
            
            // Skip if TTD amount is zero (this is a USD transaction)
            if ($amount == 0) {
                return null;
            }
        } else {
            // Use USD amount column
            $usdAmountStr = str_replace([',', ' ', '"'], '', $usdAmountStr);
            $amount = floatval($usdAmountStr);
            
            // Skip if USD amount is zero (this is a TTD transaction)
            if ($amount == 0) {
                return null;
            }
        }
        
        // Determine transaction type
        // For credit cards: positive = charge (debit), negative = payment/refund (credit)
        if ($amount < 0) {
            $transactionType = 'credit'; // Payment or refund
        } else {
            $transactionType = 'debit'; // Charge
        }
        
        // Extract reference from original amount if present (e.g., "TTD 42.00")
        $bankRef = null;
        if (preg_match('/^(TTD|USD|XCD)\s/', $originalAmount)) {
            // Original amount shows the source currency
            $bankRef = null; // No specific reference in this format
        }
        
        return [
            'transaction_date' => $transactionDate,
            'bank_ref' => $bankRef,
            'description' => $description,
            'transaction_currency' => $this->targetCurrency,
            'transaction_amount' => $amount,
            'transaction_type' => $transactionType,
        ];
    }
}
