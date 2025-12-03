<?php

namespace App\DAO;

use PDO;

class AccountDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all accounts with entity info
     */
    public function findAll(): array
    {
        $stmt = $this->db->query("
            SELECT a.*, e.entity_name, e.description as entity_description
            FROM accounts a
            JOIN entities e ON a.entity_id = e.entity_id
            ORDER BY e.entity_name, a.account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find accounts by entity name (for backward compatibility with import form)
     */
    public function findByEntityName(string $entityName, bool $activeOnly = true): array
    {
        $sql = "
            SELECT a.account_id, a.account_name, a.description, a.account_type, a.currency 
            FROM accounts a
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE e.entity_name = :entity_name
        ";
        if ($activeOnly) {
            $sql .= " AND a.is_active = 1";
        }
        $sql .= " ORDER BY a.account_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['entity_name' => $entityName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all accounts by entity ID
     */
    public function findByEntityId(int $entityId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM accounts 
            WHERE entity_id = :entity_id
            ORDER BY account_name
        ");
        $stmt->execute(['entity_id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find account by ID
     */
    public function findById(int $accountId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, e.entity_name 
            FROM accounts a
            JOIN entities e ON a.entity_id = e.entity_id
            WHERE a.account_id = :account_id
        ");
        $stmt->execute(['account_id' => $accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Check if account name exists for entity
     */
    public function existsForEntity(int $entityId, string $accountName, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            $stmt = $this->db->prepare("
                SELECT account_id FROM accounts 
                WHERE entity_id = :entity_id AND account_name = :account_name AND account_id != :account_id
            ");
            $stmt->execute(['entity_id' => $entityId, 'account_name' => $accountName, 'account_id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT account_id FROM accounts 
                WHERE entity_id = :entity_id AND account_name = :account_name
            ");
            $stmt->execute(['entity_id' => $entityId, 'account_name' => $accountName]);
        }
        return (bool)$stmt->fetch();
    }

    /**
     * Create new account
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO accounts (entity_id, account_name, description, account_type, currency, is_active)
            VALUES (:entity_id, :account_name, :description, :account_type, :currency, :is_active)
        ");
        $stmt->execute([
            'entity_id' => $data['entity_id'],
            'account_name' => $data['account_name'],
            'description' => $data['description'] ?? null,
            'account_type' => $data['account_type'] ?? 'bank',
            'currency' => $data['currency'] ?? 'TTD',
            'is_active' => $data['is_active'] ?? 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update account
     */
    public function update(int $accountId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE accounts 
            SET entity_id = :entity_id, 
                account_name = :account_name, 
                description = :description,
                account_type = :account_type,
                currency = :currency,
                is_active = :is_active
            WHERE account_id = :account_id
        ");
        return $stmt->execute([
            'account_id' => $accountId,
            'entity_id' => $data['entity_id'],
            'account_name' => $data['account_name'],
            'description' => $data['description'] ?? null,
            'account_type' => $data['account_type'] ?? 'bank',
            'currency' => $data['currency'] ?? 'TTD',
            'is_active' => $data['is_active'] ?? 1,
        ]);
    }

    /**
     * Update Akaunting link for an account
     */
    public function updateAkauntingLink(int $accountId, ?int $akauntingAccountId, ?string $akauntingAccountName): bool
    {
        $stmt = $this->db->prepare("
            UPDATE accounts 
            SET akaunting_account_id = :akaunting_account_id,
                akaunting_account_name = :akaunting_account_name
            WHERE account_id = :account_id
        ");
        return $stmt->execute([
            'account_id' => $accountId,
            'akaunting_account_id' => $akauntingAccountId,
            'akaunting_account_name' => $akauntingAccountName,
        ]);
    }

    /**
     * Clear Akaunting link for an account
     */
    public function clearAkauntingLink(int $accountId): bool
    {
        return $this->updateAkauntingLink($accountId, null, null);
    }

    /**
     * Delete account
     */
    public function delete(int $accountId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM accounts WHERE account_id = :account_id");
        return $stmt->execute(['account_id' => $accountId]);
    }

    /**
     * Check if account has import batches
     */
    public function hasImportBatches(int $accountId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM import_batches 
            WHERE account_id = :account_id
        ");
        $stmt->execute(['account_id' => $accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['cnt'] ?? 0) > 0;
    }
}
