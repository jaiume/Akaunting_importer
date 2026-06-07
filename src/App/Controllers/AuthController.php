<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\RequestCookies;
use App\Services\AuthenticationService;
use App\Services\CaptchaService;
use App\Services\ClientIpService;
use App\Services\ConfigService;
use App\Services\LoginThrottleService;

class AuthController extends BaseController
{
    private AuthenticationService $authService;
    private ConfigService $config;
    private CaptchaService $captchaService;
    private ClientIpService $clientIpService;
    private LoginThrottleService $loginThrottleService;

    public function __construct(
        Twig $view,
        AuthenticationService $authService,
        ConfigService $config,
        CaptchaService $captchaService,
        ClientIpService $clientIpService,
        LoginThrottleService $loginThrottleService
    ) {
        parent::__construct($view);
        $this->authService = $authService;
        $this->config = $config;
        $this->captchaService = $captchaService;
        $this->clientIpService = $clientIpService;
        $this->loginThrottleService = $loginThrottleService;
    }

    /**
     * Show login page
     * If user is already logged in with valid token, redirect to dashboard
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // Check if user is already authenticated via cookie
        $cookieName = $this->config::get('auth.cookie_name', 'akaunting_importer_auth');
        $token = RequestCookies::getImporterSessionToken($request, $cookieName);

        if ($token) {
            $userData = $this->authService->verifyToken($token);
            if ($userData) {
                // User is already logged in, redirect to dashboard
                return $this->redirect($response, '/dashboard');
            }
        }

        $queryParams = $this->getQueryParams($request);
        
        return $this->render($response, 'login.html.twig', [
            'success' => isset($queryParams['success']),
            'error' => $queryParams['error'] ?? null,
            'captcha' => $this->captchaService->generate(),
        ]);
    }

    /**
     * Handle login form submission
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        $email = strtolower(trim((string)($data['email'] ?? '')));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect($response, '/login?error=invalid_email');
        }

        $clientIp = $this->clientIpService->getClientIp($request);
        $blockedReason = $this->loginThrottleService->check($email, $clientIp);
        if ($blockedReason !== null) {
            $this->loginThrottleService->record($email, $clientIp, false, $blockedReason);
            return $this->redirect($response, '/login?success=1');
        }

        $captchaId = isset($data['captcha_id']) ? (string)$data['captcha_id'] : null;
        $captchaPosition = $data['captcha_position'] ?? null;
        if (!$this->captchaService->validate($captchaId, $captchaPosition)) {
            $this->loginThrottleService->record($email, $clientIp, false, 'captcha_failed');
            return $this->redirect($response, '/login?error=captcha_failed');
        }

        $sent = $this->authService->sendLoginToken($email);
        $this->loginThrottleService->record($email, $clientIp, $sent, $sent ? null : 'not_sent');

        // Always show success (don't reveal if email exists)
        return $this->redirect($response, '/login?success=1');
    }

    /**
     * Magic-link landing: show a page that POSTs the token (avoids email scanners consuming one-time GET).
     */
    public function showLoginConfirm(Request $request, Response $response): Response
    {
        $token = $this->getRouteArg($request, 'token') ?? '';
        if ($token === '') {
            return $this->redirect($response, '/login?error=invalid_token');
        }

        return $this->render($response, 'login_confirm.html.twig', [
            'token' => $token,
        ]);
    }

    /**
     * Complete login after POST (token consumed here only).
     */
    public function confirmLogin(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        if (!is_array($data)) {
            $data = [];
        }
        $postToken = $_POST['token'] ?? null;
        if (($data['token'] ?? '') === '' && is_string($postToken) && $postToken !== '') {
            $data['token'] = $postToken;
        }
        $token = trim((string)($data['token'] ?? ''));

        if ($token === '') {
            return $this->redirect($response, '/login?error=invalid_token');
        }

        $sessionToken = $this->authService->verifyLoginTokenAndCreateSession($token);

        if (!$sessionToken) {
            return $this->redirect($response, '/login?error=invalid_token');
        }

        $cookieName = $this->config::get('auth.cookie_name', 'akaunting_importer_auth');
        $expirySeconds = $this->config::get('auth.token_expiry', 604800);

        $response = $this->setCookie($response, $cookieName, $sessionToken, $expirySeconds);

        return $this->redirect($response, '/dashboard');
    }

    /**
     * Logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'akaunting_importer_auth');
        $token = RequestCookies::getImporterSessionToken($request, $cookieName);

        if ($token) {
            $this->authService->deleteToken($token);
        }

        $response = $this->clearCookie($response, $cookieName);
        
        return $this->redirect($response, '/login');
    }
}



