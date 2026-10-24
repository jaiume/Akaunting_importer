<?php

namespace App\DAO;

use PDO;

class ReceiptItemDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO receipt_items (
                receipt_batch_id, page_number, receipt_date, vendor_raw, amount, detected_currency,
                model_notes, matched_contact_id, matched_contact_name, match_confidence,
                suggested_category_id, suggested_category_name, category_id, category_name,
                payment_method, status
            ) VALUES (
                :receipt_batch_id, :page_number, :receipt_date, :vendor_raw, :amount, :detected_currency,
                :model_notes, :matched_contact_id, :matched_contact_name, :match_confidence,
                :suggested_category_id, :suggested_category_name, :category_id, :category_name,
                :payment_method, :status
            )
        ");
        $stmt->execute([
            'receipt_batch_id' => $data['receipt_batch_id'],
            'page_number' => $data['page_number'],
            'receipt_date' => $data['receipt_date'] ?? null,
            'vendor_raw' => $data['vendor_raw'] ?? null,
            'amount' => $data['amount'] ?? null,
            'detected_currency' => $data['detected_currency'] ?? null,
            'model_notes' => $data['model_notes'] ?? null,
            'matched_contact_id' => $data['matched_contact_id'] ?? null,
            'matched_contact_name' => $data['matched_contact_name'] ?? null,
            'match_confidence' => $data['match_confidence'] ?? null,
            'suggested_category_id' => $data['suggested_category_id'] ?? null,
            'suggested_category_name' => $data['suggested_category_name'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'category_name' => $data['category_name'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'status' => $data['status'] ?? 'pending',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findByBatch(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM receipt_items
            WHERE receipt_batch_id = :batch_id
            ORDER BY page_number, receipt_item_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $itemId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM receipt_items WHERE receipt_item_id = :item_id");
        $stmt->execute(['item_id' => $itemId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function pagesPresent(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT page_number FROM receipt_items WHERE receipt_batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function deleteUnpushed(int $batchId): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM receipt_items
            WHERE receipt_batch_id = :batch_id AND status <> 'pushed'
        ");
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->rowCount();
    }

    public function updateEditable(int $itemId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE receipt_items SET
                receipt_date = :receipt_date,
                vendor_raw = :vendor_raw,
                amount = :amount,
                matched_contact_id = :matched_contact_id,
                matched_contact_name = :matched_contact_name,
                match_confidence = :match_confidence,
                category_id = :category_id,
                category_name = :category_name,
                payment_method = :payment_method,
                status = :status,
                error_message = NULL
            WHERE receipt_item_id = :item_id AND status <> 'pushed'
        ");
        return $stmt->execute([
            'receipt_date' => $data['receipt_date'],
            'vendor_raw' => $data['vendor_raw'],
            'amount' => $data['amount'],
            'matched_contact_id' => $data['matched_contact_id'],
            'matched_contact_name' => $data['matched_contact_name'],
            'match_confidence' => $data['match_confidence'],
            'category_id' => $data['category_id'],
            'category_name' => $data['category_name'],
            'payment_method' => $data['payment_method'],
            'status' => $data['status'],
            'item_id' => $itemId,
        ]);
    }

    public function markSkipped(int $itemId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE receipt_items
            SET status = 'skipped', error_message = NULL
            WHERE receipt_item_id = :item_id AND status = 'pending'
        ");
        return $stmt->execute(['item_id' => $itemId]);
    }

    public function markPushed(int $itemId, string $transactionNumber): bool
    {
        $stmt = $this->db->prepare("
            UPDATE receipt_items
            SET status = 'pushed',
                pushed_akaunting_transaction_number = :number,
                pushed_at = NOW(),
                error_message = NULL
            WHERE receipt_item_id = :item_id AND status = 'pending'
        ");
        return $stmt->execute([
            'number' => $transactionNumber,
            'item_id' => $itemId,
        ]);
    }

    public function setError(int $itemId, string $message): bool
    {
        $stmt = $this->db->prepare("
            UPDATE receipt_items SET error_message = :error_message WHERE receipt_item_id = :item_id
        ");
        return $stmt->execute([
            'error_message' => $message,
            'item_id' => $itemId,
        ]);
    }

    public function transaction(callable $callback): void
    {
        $this->db->beginTransaction();
        try {
            $callback();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
