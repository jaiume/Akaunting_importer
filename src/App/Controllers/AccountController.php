<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AccountService;

class AccountController extends BaseController
{
    private AccountService $accountService;

    public function __construct(Twig $view, AccountService $accountService)
    {
        parent::__construct($view);
        $this->accountService = $accountService;
    }

    /**
     * List all accounts - redirects to entities page (combined management)
     */
    public function index(Request $request, Response $response): Response
    {
        // Redirect to the combined entities & accounts page
        return $this->redirect($response, '/settings/entities');
    }

    /**
     * Show create form
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        $entities = $this->accountService->getAllEntities();

        return $this->render($response, 'settings/accounts/form.html.twig', [
            'user' => $user,
            'account' => null,
            'entities' => $entities,
            'mode' => 'create',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Show create form with entity pre-selected
     */
    public function showCreateForEntity(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $queryParams = $this->getQueryParams($request);
        
        $entities = $this->accountService->getAllEntities();
        
        // Find the selected entity
        $selectedEntity = null;
        foreach ($entities as $entity) {
            if ($entity['entity_id'] == $entityId) {
                $selectedEntity = $entity;
                break;
            }
        }
        
        if (!$selectedEntity) {
            return $this->redirect($response, '/settings/entities?error=not_found');
        }

        return $this->render($response, 'settings/accounts/form.html.twig', [
            'user' => $user,
            'account' => ['entity_id' => $entityId, 'entity_name' => $selectedEntity['entity_name']],
            'entities' => $entities,
            'mode' => 'create',
            'entity_id' => $entityId,
            'return_to' => 'entities',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Create new account
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);

        try {
            $this->accountService->createAccount([
                'entity_id' => (int)($data['entity_id'] ?? 0),
                'account_name' => trim($data['account_name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'account_type' => trim($data['account_type'] ?? 'bank'),
                'currency' => trim($data['currency'] ?? 'TTD'),
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            return $this->redirect($response, '/settings/entities?success=account_created');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/accounts/create?error=' . $errorCode);
        }
    }

    /**
     * Create new account for specific entity
     */
    public function createForEntity(Request $request, Response $response): Response
    {
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $data = $this->getPostData($request);

        try {
            $this->accountService->createAccount([
                'entity_id' => (int)($data['entity_id'] ?? $entityId),
                'account_name' => trim($data['account_name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'account_type' => trim($data['account_type'] ?? 'bank'),
                'currency' => trim($data['currency'] ?? 'TTD'),
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            return $this->redirect($response, '/settings/entities?success=account_created');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities/' . $entityId . '/accounts/create?error=' . $errorCode);
        }
    }

    /**
     * Show edit form
     */
    public function showEdit(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $queryParams = $this->getQueryParams($request);

        $account = $this->accountService->getAccountById($accountId);
        
        if (!$account) {
            return $this->redirect($response, '/settings/accounts?error=not_found');
        }

        $entities = $this->accountService->getAllEntities();

        return $this->render($response, 'settings/accounts/form.html.twig', [
            'user' => $user,
            'account' => $account,
            'entities' => $entities,
            'mode' => 'edit',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Update account
     */
    public function update(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $data = $this->getPostData($request);

        try {
            $this->accountService->updateAccount($accountId, [
                'entity_id' => (int)($data['entity_id'] ?? 0),
                'account_name' => trim($data['account_name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'account_type' => trim($data['account_type'] ?? 'bank'),
                'currency' => trim($data['currency'] ?? 'TTD'),
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            // Redirect to entities page (combined management)
            return $this->redirect($response, '/settings/entities?success=account_updated');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/accounts/' . $accountId . '/edit?error=' . $errorCode);
        }
    }

    /**
     * Delete account
     */
    public function delete(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $queryParams = $this->getQueryParams($request);
        $returnTo = $queryParams['return'] ?? 'accounts';

        try {
            $this->accountService->deleteAccount($accountId);
            
            if ($returnTo === 'entities') {
                return $this->redirect($response, '/settings/entities?success=account_deleted');
            }
            return $this->redirect($response, '/settings/accounts?success=deleted');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            
            if ($returnTo === 'entities') {
                return $this->redirect($response, '/settings/entities?error=' . $errorCode);
            }
            return $this->redirect($response, '/settings/accounts?error=' . $errorCode);
        }
    }

    /**
     * Map exception to error code
     */
    private function mapExceptionToError(\Exception $e): string
    {
        $code = $e->getCode();
        $message = $e->getMessage();

        if ($code === 400 && strpos($message, 'required') !== false) {
            return 'required';
        }
        if ($code === 409 || strpos($message, 'exists') !== false) {
            return 'exists';
        }
        if ($code === 404) {
            return 'not_found';
        }
        if (strpos($message, 'batches') !== false) {
            return 'has_batches';
        }

        error_log('Account error: ' . $e->getMessage());
        return 'failed';
    }
}
