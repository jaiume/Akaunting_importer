<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\EntityService;

class EntityController extends BaseController
{
    private EntityService $entityService;

    public function __construct(Twig $view, EntityService $entityService)
    {
        parent::__construct($view);
        $this->entityService = $entityService;
    }

    /**
     * List all entities with their accounts
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        $entities = $this->entityService->getAllEntitiesWithAccounts();

        return $this->render($response, 'settings/entities/index.html.twig', [
            'user' => $user,
            'entities' => $entities,
            'success' => $queryParams['success'] ?? null,
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Show create form
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);

        return $this->render($response, 'settings/entities/form.html.twig', [
            'user' => $user,
            'entity' => null,
            'mode' => 'create',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Create new entity
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        $entityName = trim($data['entity_name'] ?? '');
        $description = trim($data['description'] ?? '');

        try {
            $this->entityService->createEntity($entityName, $description ?: null);
            return $this->redirect($response, '/settings/entities?success=created');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities/create?error=' . $errorCode);
        }
    }

    /**
     * Show edit form
     */
    public function showEdit(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $queryParams = $this->getQueryParams($request);

        $entity = $this->entityService->getEntityById($entityId);
        
        if (!$entity) {
            return $this->redirect($response, '/settings/entities?error=not_found');
        }

        return $this->render($response, 'settings/entities/form.html.twig', [
            'user' => $user,
            'entity' => $entity,
            'mode' => 'edit',
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Update entity
     */
    public function update(Request $request, Response $response): Response
    {
        $entityId = (int)$this->getRouteArg($request, 'entity_id');
        $data = $this->getPostData($request);
        $entityName = trim($data['entity_name'] ?? '');
        $description = trim($data['description'] ?? '');

        try {
            $this->entityService->updateEntity($entityId, $entityName, $description ?: null);
            return $this->redirect($response, '/settings/entities?success=updated');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities/' . $entityId . '/edit?error=' . $errorCode);
        }
    }

    /**
     * Delete entity
     */
    public function delete(Request $request, Response $response): Response
    {
        $entityId = (int)$this->getRouteArg($request, 'entity_id');

        try {
            $this->entityService->deleteEntity($entityId);
            return $this->redirect($response, '/settings/entities?success=deleted');
        } catch (\Exception $e) {
            $errorCode = $this->mapExceptionToError($e);
            return $this->redirect($response, '/settings/entities?error=' . $errorCode);
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
            return 'name_required';
        }
        if ($code === 409 || strpos($message, 'exists') !== false) {
            return 'exists';
        }
        if ($code === 404) {
            return 'not_found';
        }
        if (strpos($message, 'accounts') !== false) {
            return 'has_accounts';
        }

        error_log('Entity error: ' . $e->getMessage());
        return 'failed';
    }
}

