<?php

namespace App\DAO;

use PDO;

class BatchDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find batch by ID with account and entity info
     */
    public function findById(int $batchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, a.account_name, a.currency, e.entity_name, e.description as entity_description
            FROM import_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE b.batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find batch by ID and user
     */
    public function findByIdAndUser(int $batchId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, a.account_name, a.currency, e.entity_name, e.description as entity_description
            FROM import_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE b.batch_id = :batch_id AND b.user_id = :user_id
        ");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find batches by user
     */
    public function findByUser(int $userId, ?int $limit = null): array
    {
        $sql = "
            SELECT b.*, a.account_name, e.entity_name
            FROM import_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE b.user_id = :user_id 
            ORDER BY b.created_at DESC
        ";
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all batches (most recent first)
     */
    public function findAll(?int $limit = null): array
    {
        $sql = "
            SELECT b.*, a.account_name, e.entity_name
            FROM import_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
            ORDER BY b.created_at DESC
        ";
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create batch
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO import_batches 
            (batch_name, account_id, batch_import_type, import_processor, 
             batch_import_filename, batch_import_datetime, user_id, status)
            VALUES (:batch_name, :account_id, :batch_import_type, :import_processor,
                    :batch_import_filename, :batch_import_datetime, :user_id, 'pending')
        ");
        $stmt->execute([
            'batch_name' => $data['batch_name'],
            'account_id' => $data['account_id'],
            'batch_import_type' => $data['batch_import_type'],
            'import_processor' => $data['import_processor'],
            'batch_import_filename' => $data['batch_import_filename'],
            'batch_import_datetime' => $data['batch_import_datetime'],
            'user_id' => $data['user_id'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update batch status
     */
    public function updateStatus(int $batchId, string $status, ?string $errorMessage = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE import_batches 
            SET status = :status, error_message = :error_message, updated_at = NOW()
            WHERE batch_id = :batch_id
        ");
        return $stmt->execute([
            'batch_id' => $batchId,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Update total transactions count
     */
    public function updateTransactionCount(int $batchId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE import_batches 
            SET total_transactions = (SELECT COUNT(*) FROM import_transactions WHERE batch_id = :batch_id_inner)
            WHERE batch_id = :batch_id_outer
        ");
        return $stmt->execute([
            'batch_id_inner' => $batchId,
            'batch_id_outer' => $batchId
        ]);
    }

    /**
     * Check if batch belongs to user
     */
    public function belongsToUser(int $batchId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT batch_id FROM import_batches 
            WHERE batch_id = :batch_id AND user_id = :user_id
        ");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        return (bool)$stmt->fetch();
    }
}
