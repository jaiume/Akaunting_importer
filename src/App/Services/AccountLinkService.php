<?php

namespace App\Services;

use App\DAO\AccountDAO;
use App\DAO\InstallationDAO;

/**
 * Service for managing Akaunting account links
 * Links are now stored directly in the accounts table
 */
class AccountLinkService
{
    private AccountDAO $accountDAO;
    private InstallationDAO $installationDAO;
    private InstallationService $installationService;

    public function __construct(
        AccountDAO $accountDAO,
        InstallationDAO $installationDAO,
        InstallationService $installationService
    ) {
        $this->accountDAO = $accountDAO;
        $this->installationDAO = $installationDAO;
        $this->installationService = $installationService;
    }

    /**
     * Get account with its Akaunting link
     */
    public function getAccountWithLink(int $accountId): ?array
    {
        return $this->accountDAO->findById($accountId);
    }

    /**
     * Get the installation for an account (via entity)
     */
    public function getInstallationForAccount(int $accountId): ?array
    {
        $account = $this->accountDAO->findById($accountId);
        if (!$account) {
            return null;
        }
        
        // Get entity_id from account and find the installation
        return $this->installationDAO->findByEntityId($account['entity_id']);
    }

    /**
     * Fetch Akaunting accounts for an installation
     */
    public function fetchAkauntingAccounts(int $installationId, int $userId): array
    {
        return $this->installationService->fetchAkauntingAccounts($installationId, $userId);
    }

    /**
     * Save an Akaunting link for an account
     */
    public function saveLink(int $accountId, int $akauntingAccountId, string $akauntingAccountName): bool
    {
        return $this->accountDAO->updateAkauntingLink($accountId, $akauntingAccountId, $akauntingAccountName);
    }

    /**
     * Remove Akaunting link from an account
     */
    public function removeLink(int $accountId): bool
    {
        return $this->accountDAO->clearAkauntingLink($accountId);
    }
}
