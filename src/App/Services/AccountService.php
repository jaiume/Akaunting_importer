<?php

namespace App\Services;

use App\DAO\AccountDAO;
use App\DAO\EntityDAO;

class AccountService
{
    private AccountDAO $accountDAO;
    private EntityDAO $entityDAO;

    public function __construct(AccountDAO $accountDAO, EntityDAO $entityDAO)
    {
        $this->accountDAO = $accountDAO;
        $this->entityDAO = $entityDAO;
    }

    /**
     * Get all accounts
     */
    public function getAllAccounts(): array
    {
        return $this->accountDAO->findAll();
    }

    /**
     * Get accounts by entity name (for import form dropdown)
     */
    public function getAccountsByEntity(string $entityName, bool $activeOnly = true): array
    {
        return $this->accountDAO->findByEntityName($entityName, $activeOnly);
    }

    /**
     * Get account by ID
     */
    public function getAccountById(int $accountId): ?array
    {
        return $this->accountDAO->findById($accountId);
    }

    /**
     * Create new account
     * @throws \Exception if validation fails
     */
    public function createAccount(array $data): int
    {
        $this->validateAccountData($data);

        if ($this->accountDAO->existsForEntity($data['entity_id'], $data['account_name'])) {
            throw new \Exception('Account already exists for this entity', 409);
        }

        return $this->accountDAO->create($data);
    }

    /**
     * Update account
     * @throws \Exception if validation fails
     */
    public function updateAccount(int $accountId, array $data): bool
    {
        $account = $this->accountDAO->findById($accountId);
        if (!$account) {
            throw new \Exception('Account not found', 404);
        }

        $this->validateAccountData($data, true);

        if ($this->accountDAO->existsForEntity($data['entity_id'], $data['account_name'], $accountId)) {
            throw new \Exception('Account already exists for this entity', 409);
        }

        return $this->accountDAO->update($accountId, $data);
    }

    /**
     * Delete account
     * @throws \Exception if account has import batches
     */
    public function deleteAccount(int $accountId): bool
    {
        $account = $this->accountDAO->findById($accountId);
        if (!$account) {
            throw new \Exception('Account not found', 404);
        }

        // Check if account has import batches
        if ($this->accountDAO->hasImportBatches($accountId)) {
            throw new \Exception('Cannot delete account with import batches', 400);
        }

        return $this->accountDAO->delete($accountId);
    }

    /**
     * Get all entities for dropdown
     */
    public function getAllEntities(): array
    {
        return $this->entityDAO->findAll();
    }

    /**
     * Validate account data
     */
    private function validateAccountData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['entity_id'])) {
            throw new \Exception('Entity is required', 400);
        }

        if (empty($data['account_name'])) {
            throw new \Exception('Account name is required', 400);
        }

        // Verify entity exists
        if (!$this->entityDAO->findById($data['entity_id'])) {
            throw new \Exception('Entity not found', 404);
        }
    }
}
