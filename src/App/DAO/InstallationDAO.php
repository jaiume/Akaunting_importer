<?php

namespace App\DAO;

use PDO;

class InstallationDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all installations for a user
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, e.entity_name 
            FROM akaunting_installations i
            LEFT JOIN entities e ON i.entity_id = e.entity_id
            WHERE i.user_id = :user_id 
            ORDER BY i.name
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get installation for an entity (one-to-one relationship)
     */
    public function findByEntityId(int $entityId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM akaunting_installations 
            WHERE entity_id = :entity_id 
            LIMIT 1
        ");
        $stmt->execute(['entity_id' => $entityId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find installation by ID
     */
    public function findById(int $installationId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM akaunting_installations WHERE installation_id = :installation_id");
        $stmt->execute(['installation_id' => $installationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find installation by ID and user
     */
    public function findByIdAndUser(int $installationId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM akaunting_installations 
            WHERE installation_id = :installation_id AND user_id = :user_id
        ");
        $stmt->execute(['installation_id' => $installationId, 'user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create new installation
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO akaunting_installations 
            (user_id, entity_id, name, description, base_url, api_email, api_password, company_id, is_active)
            VALUES (:user_id, :entity_id, :name, :description, :base_url, :api_email, :api_password, :company_id, :is_active)
        ");
        $stmt->execute([
            'user_id' => $data['user_id'],
            'entity_id' => $data['entity_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'base_url' => $data['base_url'],
            'api_email' => $data['api_email'],
            'api_password' => $data['api_password'],
            'company_id' => $data['company_id'] ?? 1,
            'is_active' => $data['is_active'] ?? 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update installation
     */
    public function update(int $installationId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE akaunting_installations 
            SET entity_id = :entity_id,
                name = :name, 
                description = :description,
                base_url = :base_url,
                api_email = :api_email,
                api_password = :api_password,
                company_id = :company_id,
                is_active = :is_active
            WHERE installation_id = :installation_id
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'entity_id' => $data['entity_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'base_url' => $data['base_url'],
            'api_email' => $data['api_email'],
            'api_password' => $data['api_password'],
            'company_id' => $data['company_id'] ?? 1,
            'is_active' => $data['is_active'] ?? 1,
        ]);
    }

    /**
     * Update last sync time
     */
    public function updateLastSync(int $installationId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE akaunting_installations 
            SET last_sync = NOW()
            WHERE installation_id = :installation_id
        ");
        return $stmt->execute(['installation_id' => $installationId]);
    }

    /**
     * Delete installation
     */
    public function delete(int $installationId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM akaunting_installations WHERE installation_id = :installation_id");
        return $stmt->execute(['installation_id' => $installationId]);
    }

    /**
     * Check if installation belongs to user
     */
    public function belongsToUser(int $installationId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT installation_id FROM akaunting_installations 
            WHERE installation_id = :installation_id AND user_id = :user_id
        ");
        $stmt->execute(['installation_id' => $installationId, 'user_id' => $userId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Find cross-entity mapping suggestion
     */
    public function findCrossEntityMapping(
        int $sourceInstallationId,
        ?int $sourceVendorId,
        ?int $sourceCategoryId,
        int $targetInstallationId
    ): ?array {
        $sql = "
            SELECT * FROM cross_entity_mappings 
            WHERE source_installation_id = :source_installation_id 
            AND target_installation_id = :target_installation_id
        ";
        $params = [
            'source_installation_id' => $sourceInstallationId,
            'target_installation_id' => $targetInstallationId,
        ];

        if ($sourceVendorId) {
            $sql .= " AND source_vendor_id = :source_vendor_id";
            $params['source_vendor_id'] = $sourceVendorId;
        } else {
            $sql .= " AND source_vendor_id IS NULL";
        }

        if ($sourceCategoryId) {
            $sql .= " AND source_category_id = :source_category_id";
            $params['source_category_id'] = $sourceCategoryId;
        } else {
            $sql .= " AND source_category_id IS NULL";
        }

        $sql .= " ORDER BY usage_count DESC, last_used_at DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Save or update cross-entity mapping
     */
    public function saveCrossEntityMapping(
        int $sourceInstallationId,
        ?int $sourceVendorId,
        ?int $sourceCategoryId,
        int $targetInstallationId,
        ?int $targetVendorId,
        ?int $targetCategoryId,
        ?int $targetAccountId,
        ?string $targetPaymentMethod
    ): void {
        // Try to find existing mapping
        $existing = $this->findCrossEntityMapping(
            $sourceInstallationId,
            $sourceVendorId,
            $sourceCategoryId,
            $targetInstallationId
        );

        if ($existing) {
            // Update existing mapping
            $stmt = $this->db->prepare("
                UPDATE cross_entity_mappings 
                SET target_vendor_id = :target_vendor_id,
                    target_category_id = :target_category_id,
                    target_account_id = :target_account_id,
                    target_payment_method = :target_payment_method,
                    usage_count = usage_count + 1,
                    last_used_at = CURRENT_TIMESTAMP
                WHERE mapping_id = :mapping_id
            ");
            $stmt->execute([
                'target_vendor_id' => $targetVendorId,
                'target_category_id' => $targetCategoryId,
                'target_account_id' => $targetAccountId,
                'target_payment_method' => $targetPaymentMethod,
                'mapping_id' => $existing['mapping_id'],
            ]);
        } else {
            // Insert new mapping
            $stmt = $this->db->prepare("
                INSERT INTO cross_entity_mappings 
                (source_installation_id, source_vendor_id, source_category_id, 
                 target_installation_id, target_vendor_id, target_category_id, 
                 target_account_id, target_payment_method, usage_count, last_used_at)
                VALUES 
                (:source_installation_id, :source_vendor_id, :source_category_id,
                 :target_installation_id, :target_vendor_id, :target_category_id,
                 :target_account_id, :target_payment_method, 1, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                'source_installation_id' => $sourceInstallationId,
                'source_vendor_id' => $sourceVendorId,
                'source_category_id' => $sourceCategoryId,
                'target_installation_id' => $targetInstallationId,
                'target_vendor_id' => $targetVendorId,
                'target_category_id' => $targetCategoryId,
                'target_account_id' => $targetAccountId,
                'target_payment_method' => $targetPaymentMethod,
            ]);
        }
    }
}

