<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;
use App\Services\AuthenticationService;
use App\Services\ConfigService;

class AuthenticationMiddleware
{
    private $auth;
    private $config;

    public function __construct(
        AuthenticationService $auth,
        ConfigService $config
    ) {
        $this->auth = $auth;
        $this->config = $config;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $cookies = $request->getCookieParams();
        $token = $cookies[$cookieName] ?? null;

        if (!$token) {
            return $this->redirectToLogin();
        }

        $userData = $this->auth->verifyToken($token);
        if (!$userData) {
            return $this->redirectToLogin();
        }

        // Check if this is an admin route
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/admin') && !$userData['is_admin']) {
            return $this->redirectToHome();
        }

        // Extend token expiry
        $this->auth->extendTokenExpiry($token);

        // Add user data to request attributes
        $request = $request->withAttribute('user', $userData);

        return $handler->handle($request);
    }

    private function redirectToLogin(): Response
    {
        $response = new Response();
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    private function redirectToHome(): Response 
    {
        $response = new Response();
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
}

