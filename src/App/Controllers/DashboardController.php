<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\DAO\BatchDAO;
use App\Services\AuthenticationService;
use App\Services\ConfigService;

class DashboardController extends BaseController
{
    private BatchDAO $batchDAO;
    private AuthenticationService $authService;
    private ConfigService $config;

    public function __construct(
        Twig $view, 
        BatchDAO $batchDAO,
        AuthenticationService $authService,
        ConfigService $config
    ) {
        parent::__construct($view);
        $this->batchDAO = $batchDAO;
        $this->authService = $authService;
        $this->config = $config;
    }

    /**
     * Show dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        
        // Check if archived batches should be included
        $showArchived = isset($queryParams['show_archived']) && $queryParams['show_archived'] === '1';
        
        // Get batches for this user (filtered by archive status)
        $batches = $this->batchDAO->findByUser($user['user_id'], null, $showArchived);
        
        // Group batches by Entity -> Account
        $groupedBatches = [];
        foreach ($batches as $batch) {
            $entityId = $batch['entity_id'];
            $accountId = $batch['account_id'];
            
            if (!isset($groupedBatches[$entityId])) {
                $groupedBatches[$entityId] = [
                    'entity_name' => $batch['entity_name'],
                    'accounts' => []
                ];
            }
            
            if (!isset($groupedBatches[$entityId]['accounts'][$accountId])) {
                $groupedBatches[$entityId]['accounts'][$accountId] = [
                    'account_name' => $batch['account_name'],
                    'batches' => []
                ];
            }
            
            $groupedBatches[$entityId]['accounts'][$accountId]['batches'][] = $batch;
        }
        
        return $this->render($response, 'dashboard.html.twig', [
            'user' => $user,
            'batches' => $batches,
            'grouped_batches' => $groupedBatches,
            'show_archived' => $showArchived,
            'success' => $queryParams['success'] ?? null,
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Home redirect - checks auth and redirects appropriately
     */
    public function home(Request $request, Response $response): Response
    {
        // Check if user is authenticated via cookie
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $cookies = $request->getCookieParams();
        $token = $cookies[$cookieName] ?? null;
        
        if ($token) {
            $userData = $this->authService->verifyToken($token);
            if ($userData) {
                // User is logged in, go to dashboard
                return $this->redirect($response, '/dashboard');
            }
        }
        
        // Not logged in, go to login
        return $this->redirect($response, '/login');
    }
}

