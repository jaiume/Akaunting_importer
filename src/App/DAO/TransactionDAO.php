<?php

namespace App\DAO;

use PDO;

class TransactionDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find transaction by ID
     */
    public function findById(int $transactionId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM import_transactions WHERE transaction_id = :id");
        $stmt->execute(['id' => $transactionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find transactions by batch ID
     */
    public function findByBatchId(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM import_transactions 
            WHERE batch_id = :batch_id 
            ORDER BY transaction_date, transaction_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create transaction
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO import_transactions 
            (batch_id, transaction_date, bank_ref, description, original_currency, original_amount, 
             transaction_type, balance, status)
            VALUES (:batch_id, :transaction_date, :bank_ref, :description, :original_currency, 
                    :original_amount, :transaction_type, :balance, 'pending')
        ");
        $stmt->execute([
            'batch_id' => $data['batch_id'],
            'transaction_date' => $data['transaction_date'] ?? null,
            'bank_ref' => $data['bank_ref'] ?? null,
            'description' => $data['description'] ?? null,
            'original_currency' => $data['original_currency'] ?? 'TTD',
            'original_amount' => $data['original_amount'] ?? 0,
            'transaction_type' => $data['transaction_type'] ?? null,
            'balance' => $data['balance'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Bulk create transactions
     */
    public function bulkCreate(int $batchId, array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        $stmt = $this->db->prepare("
            INSERT INTO import_transactions 
            (batch_id, transaction_date, bank_ref, description, original_currency, original_amount, 
             transaction_type, balance, status)
            VALUES (:batch_id, :transaction_date, :bank_ref, :description, :original_currency, 
                    :original_amount, :transaction_type, :balance, 'pending')
        ");

        $count = 0;
        foreach ($transactions as $transaction) {
            $stmt->execute([
                'batch_id' => $batchId,
                'transaction_date' => $transaction['transaction_date'] ?? null,
                'bank_ref' => $transaction['bank_ref'] ?? null,
                'description' => $transaction['description'] ?? null,
                'original_currency' => $transaction['original_currency'] ?? 'TTD',
                'original_amount' => $transaction['original_amount'] ?? 0,
                'transaction_type' => $transaction['transaction_type'] ?? null,
                'balance' => $transaction['balance'] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Update transaction status
     */
    public function updateStatus(int $transactionId, string $status, ?string $errorMessage = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE import_transactions 
            SET status = :status, error_message = :error_message
            WHERE transaction_id = :transaction_id
        ");
        return $stmt->execute([
            'transaction_id' => $transactionId,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Count transactions by batch
     */
    public function countByBatchId(int $batchId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM import_transactions WHERE batch_id = :batch_id");
        $stmt->execute(['batch_id' => $batchId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Update match information for a transaction
     */
    public function updateMatch(
        int $transactionId,
        int $akauntingId,
        string $akauntingDate,
        float $akauntingAmount,
        ?string $akauntingContact,
        ?string $akauntingCategory,
        string $confidence
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE import_transactions 
            SET matched_akaunting_id = :akaunting_id,
                matched_akaunting_date = :akaunting_date,
                matched_akaunting_amount = :akaunting_amount,
                matched_akaunting_contact = :akaunting_contact,
                matched_akaunting_category = :akaunting_category,
                match_confidence = :confidence
            WHERE transaction_id = :transaction_id
        ");
        return $stmt->execute([
            'transaction_id' => $transactionId,
            'akaunting_id' => $akauntingId,
            'akaunting_date' => $akauntingDate,
            'akaunting_amount' => $akauntingAmount,
            'akaunting_contact' => $akauntingContact,
            'akaunting_category' => $akauntingCategory,
            'confidence' => $confidence,
        ]);
    }

    /**
     * Clear all matches for a batch
     */
    public function clearMatchesByBatch(int $batchId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE import_transactions 
            SET matched_akaunting_id = NULL,
                matched_akaunting_date = NULL,
                matched_akaunting_amount = NULL,
                matched_akaunting_contact = NULL,
                matched_akaunting_category = NULL,
                match_confidence = NULL
            WHERE batch_id = :batch_id
        ");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    /**
     * Get match statistics for a batch
     */
    public function getMatchStats(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN matched_akaunting_id IS NOT NULL THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN match_confidence = 'high' THEN 1 ELSE 0 END) as high_confidence,
                SUM(CASE WHEN match_confidence = 'medium' THEN 1 ELSE 0 END) as medium_confidence,
                SUM(CASE WHEN match_confidence = 'low' THEN 1 ELSE 0 END) as low_confidence
            FROM import_transactions 
            WHERE batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total' => 0,
            'matched' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0
        ];
    }

    /**
     * Delete all transactions for a batch
     */
    public function deleteByBatchId(int $batchId): int
    {
        $stmt = $this->db->prepare("DELETE FROM import_transactions WHERE batch_id = :batch_id");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->rowCount();
    }
}



