<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AuthenticationService;
use App\Services\ConfigService;

class AuthController extends BaseController
{
    private AuthenticationService $authService;
    private ConfigService $config;

    public function __construct(
        Twig $view,
        AuthenticationService $authService,
        ConfigService $config
    ) {
        parent::__construct($view);
        $this->authService = $authService;
        $this->config = $config;
    }

    /**
     * Show login page
     */
    public function showLogin(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        if ($user) {
            return $this->redirect($response, '/dashboard');
        }

        $queryParams = $this->getQueryParams($request);
        
        return $this->render($response, 'login.html.twig', [
            'success' => isset($queryParams['success']),
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Handle login form submission
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        $email = $data['email'] ?? '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect($response, '/login?error=invalid_email');
        }

        $this->authService->sendLoginToken($email);

        // Always show success (don't reveal if email exists)
        return $this->redirect($response, '/login?success=1');
    }

    /**
     * Verify login token
     */
    public function verifyToken(Request $request, Response $response): Response
    {
        $token = $this->getRouteArg($request, 'token') ?? '';

        if (empty($token)) {
            return $this->redirect($response, '/login?error=invalid_token');
        }

        $sessionToken = $this->authService->verifyLoginTokenAndCreateSession($token);

        if (!$sessionToken) {
            return $this->redirect($response, '/login?error=invalid_token');
        }

        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $expirySeconds = $this->config::get('auth.token_expiry', 604800);

        $response = $this->setCookie($response, $cookieName, $sessionToken, $expirySeconds);
        
        return $this->redirect($response, '/dashboard');
    }

    /**
     * Logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $cookies = $request->getCookieParams();
        $token = $cookies[$cookieName] ?? null;

        if ($token) {
            $this->authService->deleteToken($token);
        }

        $response = $this->clearCookie($response, $cookieName);
        
        return $this->redirect($response, '/login');
    }
}



