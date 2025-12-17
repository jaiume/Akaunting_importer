<?php

namespace App\DAO;

use PDO;

class VendorDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all cached contacts for an installation
     */
    public function getContactsByInstallation(int $installationId, ?string $type = null): array
    {
        $sql = "SELECT * FROM akaunting_contacts WHERE installation_id = :installation_id";
        if ($type) {
            $sql .= " AND type = :type";
        }
        $sql .= " ORDER BY name";
        
        $stmt = $this->db->prepare($sql);
        $params = ['installation_id' => $installationId];
        if ($type) {
            $params['type'] = $type;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single contact by Akaunting ID
     */
    public function getContactByAkauntingId(int $installationId, int $akauntingContactId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM akaunting_contacts 
            WHERE installation_id = :installation_id 
            AND akaunting_contact_id = :akaunting_contact_id
        ");
        $stmt->execute([
            'installation_id' => $installationId,
            'akaunting_contact_id' => $akauntingContactId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Cache/update a contact from Akaunting
     */
    public function upsertContact(int $installationId, array $contact): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO akaunting_contacts 
            (installation_id, akaunting_contact_id, name, email, type, enabled, cached_at)
            VALUES (:installation_id, :akaunting_contact_id, :name, :email, :type, :enabled, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                email = VALUES(email),
                type = VALUES(type),
                enabled = VALUES(enabled),
                cached_at = NOW(),
                updated_at = NOW()
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'akaunting_contact_id' => $contact['id'],
            'name' => $contact['name'],
            'email' => $contact['email'] ?? null,
            'type' => $contact['type'] ?? 'vendor',
            'enabled' => $contact['enabled'] ?? 1
        ]);
    }

    /**
     * Bulk cache contacts from Akaunting
     */
    public function cacheContacts(int $installationId, array $contacts): int
    {
        $count = 0;
        foreach ($contacts as $contact) {
            if ($this->upsertContact($installationId, $contact)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get the last cache time for an installation
     */
    public function getLastCacheTime(int $installationId, ?string $type = null): ?string
    {
        $sql = "SELECT MAX(cached_at) as last_cached FROM akaunting_contacts WHERE installation_id = :installation_id";
        $params = ['installation_id' => $installationId];
        
        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_cached'] ?? null;
    }

    /**
     * Clear cached contacts for an installation
     */
    public function clearCache(int $installationId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM akaunting_contacts WHERE installation_id = :installation_id");
        return $stmt->execute(['installation_id' => $installationId]);
    }

    // ============== Categories Cache ==============

    /**
     * Get all cached categories for an installation
     */
    public function getCategoriesByInstallation(int $installationId, ?string $type = null): array
    {
        $sql = "SELECT * FROM akaunting_categories WHERE installation_id = :installation_id";
        if ($type) {
            $sql .= " AND type = :type";
        }
        $sql .= " ORDER BY name";
        
        $stmt = $this->db->prepare($sql);
        $params = ['installation_id' => $installationId];
        if ($type) {
            $params['type'] = $type;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cache/update a category from Akaunting
     */
    public function upsertCategory(int $installationId, array $category): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO akaunting_categories 
            (installation_id, akaunting_category_id, name, type, color, enabled, cached_at)
            VALUES (:installation_id, :akaunting_category_id, :name, :type, :color, :enabled, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                type = VALUES(type),
                color = VALUES(color),
                enabled = VALUES(enabled),
                cached_at = NOW(),
                updated_at = NOW()
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'akaunting_category_id' => $category['id'],
            'name' => $category['name'],
            'type' => $category['type'] ?? null,
            'color' => $category['color'] ?? null,
            'enabled' => $category['enabled'] ?? 1
        ]);
    }

    /**
     * Bulk cache categories from Akaunting
     */
    public function cacheCategories(int $installationId, array $categories): int
    {
        $count = 0;
        foreach ($categories as $category) {
            if ($this->upsertCategory($installationId, $category)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get the last category cache time for an installation
     */
    public function getLastCategoryCacheTime(int $installationId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT MAX(cached_at) as last_cached 
            FROM akaunting_categories 
            WHERE installation_id = :installation_id
        ");
        $stmt->execute(['installation_id' => $installationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_cached'] ?? null;
    }

    // ============== Payment Methods Cache ==============

    /**
     * Get all cached payment methods for an installation
     */
    public function getPaymentMethodsByInstallation(int $installationId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM akaunting_payment_methods 
            WHERE installation_id = :installation_id 
            ORDER BY name
        ");
        $stmt->execute(['installation_id' => $installationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cache/update a payment method
     */
    public function upsertPaymentMethod(int $installationId, array $method): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO akaunting_payment_methods 
            (installation_id, code, name, cached_at)
            VALUES (:installation_id, :code, :name, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                cached_at = NOW(),
                updated_at = NOW()
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'code' => $method['code'],
            'name' => $method['name']
        ]);
    }

    /**
     * Bulk cache payment methods
     */
    public function cachePaymentMethods(int $installationId, array $methods): int
    {
        $count = 0;
        foreach ($methods as $method) {
            if ($this->upsertPaymentMethod($installationId, $method)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get the last payment method cache time for an installation
     */
    public function getLastPaymentMethodCacheTime(int $installationId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT MAX(cached_at) as last_cached 
            FROM akaunting_payment_methods 
            WHERE installation_id = :installation_id
        ");
        $stmt->execute(['installation_id' => $installationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_cached'] ?? null;
    }

    // ============== Transaction Mappings (Vendor + Category + Payment Method) ==============

    /**
     * Get transaction mapping for a description pattern
     */
    public function getTransactionMapping(int $installationId, string $descriptionPattern): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM transaction_mappings 
            WHERE installation_id = :installation_id 
            AND description_pattern = :pattern
        ");
        $stmt->execute([
            'installation_id' => $installationId,
            'pattern' => $descriptionPattern
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find best matching transaction mapping based on description
     * Returns the mapping with highest usage count that matches
     */
    public function findBestTransactionMapping(int $installationId, string $description): ?array
    {
        // Try exact pattern match first
        $pattern = $this->extractPattern($description);
        $mapping = $this->getTransactionMapping($installationId, $pattern);
        if ($mapping) {
            return $mapping;
        }

        // Try to find a similar pattern (first word match)
        $firstWord = strtoupper(explode(' ', trim($description))[0] ?? '');
        if (strlen($firstWord) >= 3) {
            $stmt = $this->db->prepare("
                SELECT * FROM transaction_mappings 
                WHERE installation_id = :installation_id 
                AND description_pattern LIKE :pattern
                ORDER BY usage_count DESC
                LIMIT 1
            ");
            $stmt->execute([
                'installation_id' => $installationId,
                'pattern' => $firstWord . '%'
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }

        return null;
    }

    /**
     * Create or update a transaction mapping (type + vendor + category + payment method + transfer account)
     */
    public function saveTransactionMapping(
        int $installationId, 
        string $descriptionPattern,
        ?string $transactionType,
        ?int $contactId, 
        ?string $contactName,
        ?int $categoryId,
        ?string $categoryName,
        ?string $paymentMethod,
        ?int $transferToAccountId = null
    ): bool {
        $pattern = $this->extractPattern($descriptionPattern);
        
        $stmt = $this->db->prepare("
            INSERT INTO transaction_mappings 
            (installation_id, description_pattern, transaction_type, akaunting_contact_id, contact_name, 
             akaunting_category_id, category_name, payment_method, transfer_to_account_id, usage_count)
            VALUES (:installation_id, :pattern, :transaction_type, :contact_id, :contact_name, 
                    :category_id, :category_name, :payment_method, :transfer_to_account_id, 1)
            ON DUPLICATE KEY UPDATE
                transaction_type = COALESCE(VALUES(transaction_type), transaction_type),
                akaunting_contact_id = COALESCE(VALUES(akaunting_contact_id), akaunting_contact_id),
                contact_name = COALESCE(VALUES(contact_name), contact_name),
                akaunting_category_id = COALESCE(VALUES(akaunting_category_id), akaunting_category_id),
                category_name = COALESCE(VALUES(category_name), category_name),
                payment_method = COALESCE(VALUES(payment_method), payment_method),
                transfer_to_account_id = COALESCE(VALUES(transfer_to_account_id), transfer_to_account_id),
                usage_count = usage_count + 1,
                updated_at = NOW()
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'pattern' => $pattern,
            'transaction_type' => $transactionType,
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'payment_method' => $paymentMethod,
            'transfer_to_account_id' => $transferToAccountId
        ]);
    }

    // ============== Legacy Vendor Mappings (for backwards compatibility) ==============

    /**
     * Get vendor mapping for a description pattern
     */
    public function getMapping(int $installationId, string $descriptionPattern): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vendor_mappings 
            WHERE installation_id = :installation_id 
            AND description_pattern = :pattern
        ");
        $stmt->execute([
            'installation_id' => $installationId,
            'pattern' => $descriptionPattern
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find best matching vendor based on description
     * Returns the mapping with highest usage count that matches
     */
    public function findBestMapping(int $installationId, string $description): ?array
    {
        // Try exact match first
        $pattern = $this->extractPattern($description);
        $mapping = $this->getMapping($installationId, $pattern);
        if ($mapping) {
            return $mapping;
        }

        // Try to find a similar pattern (first word match)
        $firstWord = strtoupper(explode(' ', trim($description))[0] ?? '');
        if (strlen($firstWord) >= 3) {
            $stmt = $this->db->prepare("
                SELECT * FROM vendor_mappings 
                WHERE installation_id = :installation_id 
                AND description_pattern LIKE :pattern
                ORDER BY usage_count DESC
                LIMIT 1
            ");
            $stmt->execute([
                'installation_id' => $installationId,
                'pattern' => $firstWord . '%'
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }

        return null;
    }

    /**
     * Create or update a vendor mapping
     */
    public function saveMapping(int $installationId, string $descriptionPattern, int $akauntingContactId, string $contactName): bool
    {
        $pattern = $this->extractPattern($descriptionPattern);
        
        $stmt = $this->db->prepare("
            INSERT INTO vendor_mappings 
            (installation_id, description_pattern, akaunting_contact_id, contact_name, usage_count)
            VALUES (:installation_id, :pattern, :contact_id, :contact_name, 1)
            ON DUPLICATE KEY UPDATE
                akaunting_contact_id = VALUES(akaunting_contact_id),
                contact_name = VALUES(contact_name),
                usage_count = usage_count + 1,
                updated_at = NOW()
        ");
        return $stmt->execute([
            'installation_id' => $installationId,
            'pattern' => $pattern,
            'contact_id' => $akauntingContactId,
            'contact_name' => $contactName
        ]);
    }

    /**
     * Get all mappings for an installation (sorted by usage)
     */
    public function getAllMappings(int $installationId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vendor_mappings 
            WHERE installation_id = :installation_id 
            ORDER BY usage_count DESC, description_pattern
        ");
        $stmt->execute(['installation_id' => $installationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Extract a normalized pattern from description
     * Uses first significant word/phrase for matching
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
}

