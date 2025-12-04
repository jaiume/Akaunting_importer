<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\DAO\BatchDAO;

class DashboardController extends BaseController
{
    private BatchDAO $batchDAO;

    public function __construct(Twig $view, BatchDAO $batchDAO)
    {
        parent::__construct($view);
        $this->batchDAO = $batchDAO;
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
        
        return $this->render($response, 'dashboard.html.twig', [
            'user' => $user,
            'batches' => $batches,
            'show_archived' => $showArchived,
            'success' => $queryParams['success'] ?? null,
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Home redirect
     */
    public function home(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        
        if ($user) {
            return $this->redirect($response, '/dashboard');
        }
        
        return $this->redirect($response, '/login');
    }
}

