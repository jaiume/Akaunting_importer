<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class BaseController
{
    protected Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    /**
     * Render a template
     */
    protected function render(Response $response, string $template, array $data = []): Response
    {
        return $this->view->render($response, $template, $data);
    }

    /**
     * Redirect to a URL
     */
    protected function redirect(Response $response, string $url, int $status = 302): Response
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }

    /**
     * Get current user from request
     */
    protected function getUser(Request $request): ?array
    {
        return $request->getAttribute('user');
    }

    /**
     * Get query parameters
     */
    protected function getQueryParams(Request $request): array
    {
        return $request->getQueryParams();
    }

    /**
     * Get parsed body (POST data)
     */
    protected function getPostData(Request $request): array
    {
        return $request->getParsedBody() ?? [];
    }

    /**
     * Get route argument
     */
    protected function getRouteArg(Request $request, string $name): ?string
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        return $route ? $route->getArgument($name) : null;
    }

    /**
     * Return JSON response
     */
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Set cookie
     */
    protected function setCookie(Response $response, string $name, string $value, int $maxAge): Response
    {
        return $response->withHeader('Set-Cookie', sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d',
            $name,
            $value,
            $maxAge
        ));
    }

    /**
     * Clear cookie
     */
    protected function clearCookie(Response $response, string $name): Response
    {
        return $response->withHeader('Set-Cookie', sprintf(
            '%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0',
            $name
        ));
    }
}



