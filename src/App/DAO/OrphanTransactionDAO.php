<?php

namespace App\DAO;

use PDO;

class OrphanTransactionDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Save orphan transactions for a batch
     * Clears existing orphans first, then inserts new ones
     */
    public function saveOrphans(int $batchId, array $orphans): int
    {
        // Clear existing orphans for this batch
        $this->deleteByBatchId($batchId);
        
        if (empty($orphans)) {
            return 0;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO akaunting_orphan_transactions 
            (batch_id, akaunting_id, akaunting_number, transaction_date, amount, type, description, reference, contact, category, currency_code)
            VALUES 
            (:batch_id, :akaunting_id, :akaunting_number, :transaction_date, :amount, :type, :description, :reference, :contact, :category, :currency_code)
        ");
        
        $count = 0;
        foreach ($orphans as $orphan) {
            $stmt->execute([
                'batch_id' => $batchId,
                'akaunting_id' => $orphan['id'],
                'akaunting_number' => $orphan['number'] ?? null,
                'transaction_date' => $orphan['date'],
                'amount' => $orphan['amount'],
                'type' => $orphan['type'] ?? null,
                'description' => $orphan['description'] ?? null,
                'reference' => $orphan['reference'] ?? null,
                'contact' => $orphan['contact'] ?? null,
                'category' => $orphan['category'] ?? null,
                'currency_code' => $orphan['currency_code'] ?? 'TTD',
            ]);
            $count++;
        }
        
        return $count;
    }

    /**
     * Find orphan transactions by batch ID
     */
    public function findByBatchId(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM akaunting_orphan_transactions 
            WHERE batch_id = :batch_id 
            ORDER BY transaction_date, orphan_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete orphan transactions by batch ID
     */
    public function deleteByBatchId(int $batchId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM akaunting_orphan_transactions WHERE batch_id = :batch_id");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    /**
     * Get count of orphans for a batch
     */
    public function countByBatchId(int $batchId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM akaunting_orphan_transactions WHERE batch_id = :batch_id");
        $stmt->execute(['batch_id' => $batchId]);
        return (int)$stmt->fetchColumn();
    }
}











