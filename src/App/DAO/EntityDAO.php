<?php

namespace App\DAO;

use PDO;

class EntityDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all entities with account count
     */
    public function findAll(): array
    {
        $stmt = $this->db->query("
            SELECT e.*, 
                   (SELECT COUNT(*) FROM accounts a WHERE a.entity_id = e.entity_id) as account_count
            FROM entities e 
            ORDER BY e.entity_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find entity by ID
     */
    public function findById(int $entityId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM entities WHERE entity_id = :entity_id");
        $stmt->execute(['entity_id' => $entityId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find entity by name
     */
    public function findByName(string $entityName): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM entities WHERE entity_name = :entity_name");
        $stmt->execute(['entity_name' => $entityName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Check if entity name exists (excluding given ID)
     */
    public function nameExists(string $entityName, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            $stmt = $this->db->prepare("SELECT entity_id FROM entities WHERE entity_name = :entity_name AND entity_id != :entity_id");
            $stmt->execute(['entity_name' => $entityName, 'entity_id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT entity_id FROM entities WHERE entity_name = :entity_name");
            $stmt->execute(['entity_name' => $entityName]);
        }
        return (bool)$stmt->fetch();
    }

    /**
     * Create new entity
     */
    public function create(string $entityName, ?string $description = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO entities (entity_name, description)
            VALUES (:entity_name, :description)
        ");
        $stmt->execute([
            'entity_name' => $entityName,
            'description' => $description,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update entity
     */
    public function update(int $entityId, string $entityName, ?string $description = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE entities 
            SET entity_name = :entity_name, description = :description
            WHERE entity_id = :entity_id
        ");
        return $stmt->execute([
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'description' => $description,
        ]);
    }

    /**
     * Delete entity
     */
    public function delete(int $entityId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM entities WHERE entity_id = :entity_id");
        return $stmt->execute(['entity_id' => $entityId]);
    }

    /**
     * Get account count for entity
     */
    public function getAccountCount(int $entityId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt 
            FROM accounts 
            WHERE entity_id = :entity_id
        ");
        $stmt->execute(['entity_id' => $entityId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['cnt'] ?? 0);
    }
}

