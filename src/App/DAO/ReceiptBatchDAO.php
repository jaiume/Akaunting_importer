<?php

namespace App\DAO;

use PDO;

class ReceiptBatchDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO receipt_batches
                (batch_name, account_id, stored_filename, original_filename, user_id, status, page_count)
            VALUES
                (:batch_name, :account_id, :stored_filename, :original_filename, :user_id, :status, :page_count)
        ");
        $stmt->execute([
            'batch_name' => $data['batch_name'],
            'account_id' => $data['account_id'],
            'stored_filename' => $data['stored_filename'],
            'original_filename' => $data['original_filename'] ?? null,
            'user_id' => $data['user_id'],
            'status' => $data['status'] ?? 'pending',
            'page_count' => $data['page_count'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findById(int $batchId): ?array
    {
        $stmt = $this->db->prepare($this->selectSql() . " WHERE b.receipt_batch_id = :batch_id");
        $stmt->execute(['batch_id' => $batchId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndUser(int $batchId, int $userId): ?array
    {
        $stmt = $this->db->prepare($this->selectSql() . " WHERE b.receipt_batch_id = :batch_id AND b.user_id = :user_id");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByUser(int $userId, bool $includeArchived = false): array
    {
        $sql = "
            SELECT b.*, a.account_name, a.entity_id, a.currency, e.entity_name,
                   (SELECT MIN(i.receipt_date) FROM receipt_items i WHERE i.receipt_batch_id = b.receipt_batch_id) AS min_date,
                   (SELECT MAX(i.receipt_date) FROM receipt_items i WHERE i.receipt_batch_id = b.receipt_batch_id) AS max_date,
                   (SELECT COUNT(*) FROM receipt_items i WHERE i.receipt_batch_id = b.receipt_batch_id) AS item_count,
                   (SELECT COUNT(*) FROM receipt_items i WHERE i.receipt_batch_id = b.receipt_batch_id AND i.status = 'pushed') AS pushed_count
            FROM receipt_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE b.user_id = :user_id
        ";
        if (!$includeArchived) {
            $sql .= " AND b.is_archived = 0";
        }
        $sql .= " ORDER BY e.entity_name, a.account_name, b.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $batchId, string $status, ?string $errorMessage = null, ?int $pageCount = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE receipt_batches
            SET status = :status,
                error_message = :error_message,
                page_count = COALESCE(:page_count, page_count)
            WHERE receipt_batch_id = :batch_id
        ");
        return $stmt->execute([
            'status' => $status,
            'error_message' => $errorMessage,
            'page_count' => $pageCount,
            'batch_id' => $batchId,
        ]);
    }

    public function archive(int $batchId): bool
    {
        $stmt = $this->db->prepare("UPDATE receipt_batches SET is_archived = 1 WHERE receipt_batch_id = :batch_id");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    public function unarchive(int $batchId): bool
    {
        $stmt = $this->db->prepare("UPDATE receipt_batches SET is_archived = 0 WHERE receipt_batch_id = :batch_id");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    public function delete(int $batchId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM receipt_batches WHERE receipt_batch_id = :batch_id");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    private function selectSql(): string
    {
        return "
            SELECT b.*, a.account_name, a.currency, a.akaunting_account_id, a.entity_id, e.entity_name
            FROM receipt_batches b
            JOIN accounts a ON b.account_id = a.account_id
            JOIN entities e ON a.entity_id = e.entity_id
        ";
    }
}
