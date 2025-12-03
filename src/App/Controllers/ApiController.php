<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AccountService;
use App\Services\AccountLinkService;
use App\Services\InstallationService;

class ApiController extends BaseController
{
    private AccountService $accountService;
    private AccountLinkService $accountLinkService;
    private InstallationService $installationService;

    public function __construct(
        Twig $view, 
        AccountService $accountService,
        AccountLinkService $accountLinkService,
        InstallationService $installationService
    ) {
        parent::__construct($view);
        $this->accountService = $accountService;
        $this->accountLinkService = $accountLinkService;
        $this->installationService = $installationService;
    }

    /**
     * Get accounts for entity (AJAX endpoint)
     */
    public function getAccountsByEntity(Request $request, Response $response): Response
    {
        $entityName = $this->getRouteArg($request, 'entity_name') ?? '';

        try {
            $accounts = $this->accountService->getAccountsByEntity($entityName);
            return $this->json($response, ['success' => true, 'accounts' => $accounts]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch Akaunting accounts for an installation (AJAX endpoint)
     */
    public function getAkauntingAccounts(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');

        try {
            $accounts = $this->installationService->fetchAkauntingAccounts($installationId, $user['user_id']);
            return $this->json($response, ['success' => true, 'accounts' => $accounts]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get account with its Akaunting link and the entity's installation
     */
    public function getAccountLinks(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $account = $this->accountLinkService->getAccountWithLink($accountId);
            if (!$account) {
                return $this->json($response, ['success' => false, 'error' => 'Account not found'], 404);
            }
            
            $installation = $this->accountLinkService->getInstallationForAccount($accountId);
            
            // Build link info if account has an Akaunting link
            $link = null;
            if ($account['akaunting_account_id']) {
                $link = [
                    'akaunting_account_id' => $account['akaunting_account_id'],
                    'akaunting_account_name' => $account['akaunting_account_name'],
                ];
            }
            
            return $this->json($response, [
                'success' => true, 
                'account' => $account,
                'link' => $link,
                'installation' => $installation
            ]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save an Akaunting link for an account
     */
    public function saveAccountLink(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        try {
            $akauntingAccountId = (int)($data['akaunting_account_id'] ?? 0);
            $akauntingAccountName = $data['akaunting_account_name'] ?? '';

            if (!$akauntingAccountId || !$akauntingAccountName) {
                return $this->json($response, ['success' => false, 'error' => 'Missing required fields'], 400);
            }

            $success = $this->accountLinkService->saveLink(
                $accountId,
                $akauntingAccountId,
                $akauntingAccountName
            );

            return $this->json($response, ['success' => $success]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove Akaunting link from an account
     */
    public function deleteAccountLink(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $success = $this->accountLinkService->removeLink($accountId);
            return $this->json($response, ['success' => $success]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
