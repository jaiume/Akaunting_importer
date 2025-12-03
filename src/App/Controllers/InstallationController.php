<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\InstallationService;
use App\Services\EntityService;

class InstallationController extends BaseController
{
    private InstallationService $installationService;
    private EntityService $entityService;

    public function __construct(Twig $view, InstallationService $installationService, EntityService $entityService)
    {
        parent::__construct($view);
        $this->installationService = $installationService;
        $this->entityService = $entityService;
    }

    /**
     * List all installations - redirects to entities page
     */
    public function index(Request $request, Response $response): Response
    {
        return $this->redirect($response, '/settings/entities');
    }

    /**
     * Show create form (standalone - without entity)
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        $entities = $this->entityService->getAllEntities();

        return $this->render($response, 'settings/installations/form.html.twig', [
            'user' => $user,
            'installation' => null,
            'entities' => $entities,
            'mode' => 'create',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Show create form for specific entity
     */
    public function showCreateForEntity(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $queryParams = $this->getQueryParams($request);
        
        $entity = $this->entityService->getEntityById($entityId);
        if (!$entity) {
            return $this->redirect($response, '/settings/entities?error=not_found');
        }
        
        $entities = $this->entityService->getAllEntities();

        return $this->render($response, 'settings/installations/form.html.twig', [
            'user' => $user,
            'installation' => ['entity_id' => $entityId],
            'entities' => $entities,
            'entity_id' => $entityId,
            'mode' => 'create',
            'return_to' => 'entities',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Create new installation (standalone)
     */
    public function create(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $data = $this->getPostData($request);

        try {
            $this->installationService->createInstallation([
                'user_id' => $user['user_id'],
                'entity_id' => !empty($data['entity_id']) ? (int)$data['entity_id'] : null,
                'name' => trim($data['name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'base_url' => trim($data['base_url'] ?? ''),
                'api_email' => trim($data['api_email'] ?? ''),
                'api_password' => $data['api_password'] ?? '',
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            return $this->redirect($response, '/settings/entities?success=installation_created');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/installations/create?error=' . $errorCode);
        }
    }

    /**
     * Create new installation for specific entity
     */
    public function createForEntity(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $data = $this->getPostData($request);

        try {
            $this->installationService->createInstallation([
                'user_id' => $user['user_id'],
                'entity_id' => $entityId,
                'name' => trim($data['name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'base_url' => trim($data['base_url'] ?? ''),
                'api_email' => trim($data['api_email'] ?? ''),
                'api_password' => $data['api_password'] ?? '',
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            return $this->redirect($response, '/settings/entities?success=installation_created');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities/' . $entityId . '/installations/create?error=' . $errorCode);
        }
    }

    /**
     * Show edit form
     */
    public function showEdit(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');
        $queryParams = $this->getQueryParams($request);

        $installation = $this->installationService->getInstallationByIdAndUser($installationId, $user['user_id']);
        
        if (!$installation) {
            return $this->redirect($response, '/settings/entities?error=not_found');
        }

        $entities = $this->entityService->getAllEntities();

        return $this->render($response, 'settings/installations/form.html.twig', [
            'user' => $user,
            'installation' => $installation,
            'entities' => $entities,
            'mode' => 'edit',
            'return_to' => $queryParams['return'] ?? 'entities',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Update installation
     */
    public function update(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');
        $data = $this->getPostData($request);

        try {
            $this->installationService->updateInstallation($installationId, $user['user_id'], [
                'entity_id' => !empty($data['entity_id']) ? (int)$data['entity_id'] : null,
                'name' => trim($data['name'] ?? ''),
                'description' => trim($data['description'] ?? '') ?: null,
                'base_url' => trim($data['base_url'] ?? ''),
                'api_email' => trim($data['api_email'] ?? ''),
                'api_password' => $data['api_password'] ?? '',
                'is_active' => isset($data['is_active']) ? 1 : 0,
            ]);
            return $this->redirect($response, '/settings/entities?success=installation_updated');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/installations/' . $installationId . '/edit?error=' . $errorCode);
        }
    }

    /**
     * Delete installation
     */
    public function delete(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');
        $queryParams = $this->getQueryParams($request);

        try {
            $this->installationService->deleteInstallation($installationId, $user['user_id']);
            return $this->redirect($response, '/settings/entities?success=installation_deleted');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities?error=' . $errorCode);
        }
    }

    /**
     * Test connection to installation (returns JSON for AJAX)
     */
    public function testConnection(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');

        try {
            $result = $this->installationService->testConnection($installationId, $user['user_id']);
            
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Map exception to error code
     */
    private function mapExceptionToError(\Exception $e): string
    {
        $code = $e->getCode();
        $message = $e->getMessage();

        if (strpos($message, 'Name is required') !== false) {
            return 'name_required';
        }
        if (strpos($message, 'URL') !== false) {
            return 'url_invalid';
        }
        if (strpos($message, 'Email') !== false) {
            return 'email_required';
        }
        if (strpos($message, 'Password') !== false) {
            return 'password_required';
        }
        if ($code === 404) {
            return 'not_found';
        }

        error_log('Installation error: ' . $e->getMessage());
        return 'failed';
    }
}
