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
}

