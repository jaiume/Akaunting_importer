<?php

namespace App\Services;

use App\DAO\EntityDAO;
use App\DAO\AccountDAO;
use App\DAO\InstallationDAO;

class EntityService
{
    private EntityDAO $entityDAO;
    private AccountDAO $accountDAO;
    private InstallationDAO $installationDAO;

    public function __construct(EntityDAO $entityDAO, AccountDAO $accountDAO, InstallationDAO $installationDAO)
    {
        $this->entityDAO = $entityDAO;
        $this->accountDAO = $accountDAO;
        $this->installationDAO = $installationDAO;
    }

    /**
     * Get all entities
     */
    public function getAllEntities(): array
    {
        return $this->entityDAO->findAll();
    }

    /**
     * Get all entities with their accounts and installation (one installation per entity)
     */
    public function getAllEntitiesWithAccounts(): array
    {
        $entities = $this->entityDAO->findAll();
        
        foreach ($entities as &$entity) {
            $entity['accounts'] = $this->accountDAO->findByEntityId($entity['entity_id']);
            $entity['installation'] = $this->installationDAO->findByEntityId($entity['entity_id']);
        }
        
        return $entities;
    }

    /**
     * Get entity by ID
     */
    public function getEntityById(int $entityId): ?array
    {
        return $this->entityDAO->findById($entityId);
    }

    /**
     * Create new entity
     * @throws \Exception if validation fails
     */
    public function createEntity(string $entityName, ?string $description = null): int
    {
        $entityName = trim($entityName);
        
        if (empty($entityName)) {
            throw new \Exception('Entity name is required', 400);
        }

        if ($this->entityDAO->nameExists($entityName)) {
            throw new \Exception('Entity name already exists', 409);
        }

        return $this->entityDAO->create($entityName, $description);
    }

    /**
     * Update entity
     * @throws \Exception if validation fails
     */
    public function updateEntity(int $entityId, string $entityName, ?string $description = null): bool
    {
        $entityName = trim($entityName);
        
        if (empty($entityName)) {
            throw new \Exception('Entity name is required', 400);
        }

        $entity = $this->entityDAO->findById($entityId);
        if (!$entity) {
            throw new \Exception('Entity not found', 404);
        }

        if ($this->entityDAO->nameExists($entityName, $entityId)) {
            throw new \Exception('Entity name already exists', 409);
        }

        return $this->entityDAO->update($entityId, $entityName, $description);
    }

    /**
     * Delete entity
     * @throws \Exception if entity has accounts or installation
     */
    public function deleteEntity(int $entityId): bool
    {
        $accountCount = $this->entityDAO->getAccountCount($entityId);
        
        if ($accountCount > 0) {
            throw new \Exception('Cannot delete entity with associated accounts', 400);
        }

        $installation = $this->installationDAO->findByEntityId($entityId);
        if ($installation) {
            throw new \Exception('Cannot delete entity with associated Akaunting installation', 400);
        }

        return $this->entityDAO->delete($entityId);
    }
}

