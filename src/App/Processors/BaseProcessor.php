<?php

namespace App\Processors;

use PDO;

/**
 * Base class for all import processors
 */
abstract class BaseProcessor
{
    protected $db;
    protected $batchId;
    protected $filePath;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set the batch ID and file path for this import
     */
    public function setImportData(int $batchId, string $filePath): void
    {
        $this->batchId = $batchId;
        $this->filePath = $filePath;
    }

    /**
     * Process the import file and extract transactions
     * Returns array of transaction data
     */
    abstract public function process(): array;

    /**
     * Validate the file format and structure
     * Returns true if valid, throws exception if invalid
     */
    abstract public function validate(): bool;

    /**
     * Parse transactions from the file
     * Returns array of transaction arrays
     */
    abstract protected function parseTransactions(): array;

    /**
     * Save transactions to the database
     * Wrapped in a database transaction for atomicity - if any insert fails, all are rolled back
     */
    protected function saveTransactions(array $transactions): void
    {
        if (empty($transactions)) {
            return;
        }

        // Begin database transaction
        $this->db->beginTransaction();

        try {
            // First, delete any existing transactions for this batch (in case of retry)
            $deleteStmt = $this->db->prepare("
                DELETE FROM import_transactions WHERE batch_id = :batch_id
            ");
            $deleteStmt->execute(['batch_id' => $this->batchId]);

            // Prepare insert statement
            $stmt = $this->db->prepare("
                INSERT INTO import_transactions 
                (batch_id, transaction_date, bank_ref, description, transaction_currency, transaction_amount, 
                 transaction_type, status)
                VALUES (:batch_id, :transaction_date, :bank_ref, :description, :transaction_currency, 
                        :transaction_amount, :transaction_type, 'pending')
            ");

            // Insert all transactions
            foreach ($transactions as $index => $transaction) {
                $stmt->execute([
                    'batch_id' => $this->batchId,
                    'transaction_date' => $transaction['transaction_date'] ?? null,
                    'bank_ref' => $transaction['bank_ref'] ?? null,
                    'description' => $transaction['description'] ?? null,
                    'transaction_currency' => $transaction['transaction_currency'] ?? 'TTD',
                    'transaction_amount' => $transaction['transaction_amount'] ?? 0,
                    'transaction_type' => $transaction['transaction_type'] ?? null,
                ]);
            }

            // Update batch with total transactions count
            $countStmt = $this->db->prepare("
                UPDATE import_batches 
                SET total_transactions = :count
                WHERE batch_id = :batch_id
            ");
            $countStmt->execute([
                'count' => count($transactions),
                'batch_id' => $this->batchId
            ]);

            // Commit the transaction
            $this->db->commit();

        } catch (\Exception $e) {
            // Rollback on any error
            $this->db->rollBack();
            throw new \Exception("Failed to save transactions: " . $e->getMessage());
        }
    }

    /**
     * Update batch status
     * When setting to 'processing', clears previous error message and resets counters for retry
     */
    protected function updateBatchStatus(string $status, ?string $errorMessage = null): void
    {
        // When starting processing (including retry), clear error and reset counters
        if ($status === 'processing') {
            $stmt = $this->db->prepare("
                UPDATE import_batches 
                SET status = :status, 
                    error_message = NULL, 
                    processed_transactions = 0,
                    updated_at = NOW()
                WHERE batch_id = :batch_id
            ");
            $stmt->execute([
                'batch_id' => $this->batchId,
                'status' => $status,
            ]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE import_batches 
                SET status = :status, error_message = :error_message, updated_at = NOW()
                WHERE batch_id = :batch_id
            ");
            $stmt->execute([
                'batch_id' => $this->batchId,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        }
    }

    /**
     * Check if the batch can be processed (pending or failed status)
     */
    public function canProcess(): bool
    {
        $stmt = $this->db->prepare("
            SELECT status FROM import_batches WHERE batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $this->batchId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return false;
        }
        
        return in_array($result['status'], ['pending', 'failed']);
    }
}

