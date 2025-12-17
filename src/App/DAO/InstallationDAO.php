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

    // ============== Replication Transaction Mappings ==============

    /**
     * Extract a normalized pattern from description
     * Uses first significant word/phrase for matching (same logic as VendorDAO)
     */
    private function extractPattern(string $description): string
    {
        // Normalize: uppercase, remove extra spaces
        $pattern = strtoupper(trim($description));
        
        // Remove common prefixes
        $prefixes = ['PAYPAL *', 'PAYPAL*', 'SQ *', 'SP ', 'TST*'];
        foreach ($prefixes as $prefix) {
            if (strpos($pattern, $prefix) === 0) {
                $pattern = substr($pattern, strlen($prefix));
                break;
            }
        }
        
        // Take first 2-3 significant words (up to 50 chars)
        $words = preg_split('/\s+/', trim($pattern));
        $significant = [];
        $len = 0;
        foreach ($words as $word) {
            if ($len + strlen($word) > 50) break;
            $significant[] = $word;
            $len += strlen($word) + 1;
            if (count($significant) >= 3) break;
        }
        
        return implode(' ', $significant);
    }

    /**
     * Find best replication mapping for a description across all target installations
     * Returns the mapping with highest usage count
     * Used for immediate entity pre-selection when opening replicate form
     */
    public function findBestReplicationMapping(int $sourceInstallationId, string $description): ?array
    {
        $pattern = $this->extractPattern($description);
        
        // Try exact pattern match first
        $stmt = $this->db->prepare("
            SELECT rtm.*, i.entity_id as target_entity_id, e.entity_name as target_entity_name
            FROM replication_transaction_mappings rtm
            JOIN akaunting_installations i ON rtm.target_installation_id = i.installation_id
            LEFT JOIN entities e ON i.entity_id = e.entity_id
            WHERE rtm.source_installation_id = :source_installation_id 
            AND rtm.description_pattern = :pattern
            ORDER BY rtm.usage_count DESC
            LIMIT 1
        ");
        $stmt->execute([
            'source_installation_id' => $sourceInstallationId,
            'pattern' => $pattern
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result;
        }

        // Try first word match as fallback
        $firstWord = strtoupper(explode(' ', trim($description))[0] ?? '');
        if (strlen($firstWord) >= 3) {
            $stmt = $this->db->prepare("
                SELECT rtm.*, i.entity_id as target_entity_id, e.entity_name as target_entity_name
                FROM replication_transaction_mappings rtm
                JOIN akaunting_installations i ON rtm.target_installation_id = i.installation_id
                LEFT JOIN entities e ON i.entity_id = e.entity_id
                WHERE rtm.source_installation_id = :source_installation_id 
                AND rtm.description_pattern LIKE :pattern
                ORDER BY rtm.usage_count DESC
                LIMIT 1
            ");
            $stmt->execute([
                'source_installation_id' => $sourceInstallationId,
                'pattern' => $firstWord . '%'
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }

        return null;
    }

    /**
     * Find replication mapping for a specific target installation
     * Used when populating form after entity is selected
     */
    public function findReplicationMappingForTarget(
        int $sourceInstallationId, 
        int $targetInstallationId, 
        string $description
    ): ?array {
        $pattern = $this->extractPattern($description);
        
        // Try exact pattern match first
        $stmt = $this->db->prepare("
            SELECT * FROM replication_transaction_mappings 
            WHERE source_installation_id = :source_installation_id 
            AND target_installation_id = :target_installation_id
            AND description_pattern = :pattern
        ");
        $stmt->execute([
            'source_installation_id' => $sourceInstallationId,
            'target_installation_id' => $targetInstallationId,
            'pattern' => $pattern
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result;
        }

        // Try first word match as fallback
        $firstWord = strtoupper(explode(' ', trim($description))[0] ?? '');
        if (strlen($firstWord) >= 3) {
            $stmt = $this->db->prepare("
                SELECT * FROM replication_transaction_mappings 
                WHERE source_installation_id = :source_installation_id 
                AND target_installation_id = :target_installation_id
                AND description_pattern LIKE :pattern
                ORDER BY usage_count DESC
                LIMIT 1
            ");
            $stmt->execute([
                'source_installation_id' => $sourceInstallationId,
                'target_installation_id' => $targetInstallationId,
                'pattern' => $firstWord . '%'
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }

        return null;
    }

    /**
     * Save or update replication transaction mapping
     * Called after successful replication to learn from user choices
     */
    public function saveReplicationMapping(
        int $sourceInstallationId,
        int $targetInstallationId,
        string $description,
        ?string $transactionType,
        ?int $targetContactId,
        ?string $targetContactName,
        ?int $targetCategoryId,
        ?string $targetCategoryName,
        ?int $targetAccountId,
        ?string $targetPaymentMethod
    ): bool {
        $pattern = $this->extractPattern($description);
        
        $stmt = $this->db->prepare("
            INSERT INTO replication_transaction_mappings 
            (source_installation_id, target_installation_id, description_pattern, transaction_type,
             target_contact_id, target_contact_name, target_category_id, target_category_name,
             target_account_id, target_payment_method, usage_count)
            VALUES 
            (:source_installation_id, :target_installation_id, :pattern, :transaction_type,
             :target_contact_id, :target_contact_name, :target_category_id, :target_category_name,
             :target_account_id, :target_payment_method, 1)
            ON DUPLICATE KEY UPDATE
                transaction_type = COALESCE(VALUES(transaction_type), transaction_type),
                target_contact_id = COALESCE(VALUES(target_contact_id), target_contact_id),
                target_contact_name = COALESCE(VALUES(target_contact_name), target_contact_name),
                target_category_id = COALESCE(VALUES(target_category_id), target_category_id),
                target_category_name = COALESCE(VALUES(target_category_name), target_category_name),
                target_account_id = COALESCE(VALUES(target_account_id), target_account_id),
                target_payment_method = COALESCE(VALUES(target_payment_method), target_payment_method),
                usage_count = usage_count + 1,
                updated_at = NOW()
        ");
        return $stmt->execute([
            'source_installation_id' => $sourceInstallationId,
            'target_installation_id' => $targetInstallationId,
            'pattern' => $pattern,
            'transaction_type' => $transactionType,
            'target_contact_id' => $targetContactId,
            'target_contact_name' => $targetContactName,
            'target_category_id' => $targetCategoryId,
            'target_category_name' => $targetCategoryName,
            'target_account_id' => $targetAccountId,
            'target_payment_method' => $targetPaymentMethod,
        ]);
    }
}

