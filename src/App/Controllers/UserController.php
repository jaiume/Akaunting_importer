<?php

namespace App\Controllers;

use App\Services\CsrfService;
use App\Services\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class UserController extends BaseController
{
    private UserService $userService;
    private CsrfService $csrfService;

    public function __construct(Twig $view, UserService $userService, CsrfService $csrfService)
    {
        parent::__construct($view);
        $this->userService = $userService;
        $this->csrfService = $csrfService;
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $this->getQueryParams($request);
        $overview = $this->userService->getUsersOverview();

        return $this->render($response, 'settings/users/index.html.twig', [
            'user' => $this->getUser($request),
            'approved_users' => $overview['approved_users'],
            'unapproved_count' => $overview['unapproved_count'],
            'recent_unapproved_users' => $overview['recent_unapproved_users'],
            'csrf_token' => $this->csrfService->generate('settings_users_add'),
            'success' => $queryParams['success'] ?? null,
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    public function add(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        $currentUser = $this->getUser($request);

        if (!$this->csrfService->validate('settings_users_add', isset($data['csrf_token']) ? (string)$data['csrf_token'] : null)) {
            return $this->redirect($response, '/settings/users?error=csrf');
        }

        try {
            $this->userService->addApprovedUser((string)($data['email'] ?? ''), (int)$currentUser['user_id']);
            return $this->redirect($response, '/settings/users?success=added');
        } catch (\Exception $e) {
            return $this->redirect($response, '/settings/users?error=' . $this->mapExceptionToError($e));
        }
    }

    private function mapExceptionToError(\Exception $e): string
    {
        if ($e->getCode() === 400) {
            return 'invalid_email';
        }
        if ($e->getCode() === 403) {
            return 'not_approved';
        }

        error_log('User management error: ' . $e->getMessage());
        return 'failed';
    }
}
