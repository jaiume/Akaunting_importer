<?php

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Middleware\AuthenticationMiddleware;
use Slim\Views\Twig;

return function (App $app) {
    $container = $app->getContainer();
    
    // Apply authentication middleware to all routes except login/register
    $authMiddleware = $container->get(AuthenticationMiddleware::class);
    
    // Public routes (no authentication required)
    $app->get('/', function (Request $request, Response $response) {
        // If authenticated, show dashboard; otherwise redirect to login
        $user = $request->getAttribute('user');
        if ($user) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        return $response->withHeader('Location', '/login')->withStatus(302);
    });
    
    $app->get('/login', function (Request $request, Response $response) use ($container) {
        // Login page
        $user = $request->getAttribute('user');
        if ($user) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $view = $container->get('view');
        return $view->render($response, 'login.html.twig');
    });
    
    $app->post('/login', function (Request $request, Response $response) {
        // Handle login POST
        $data = $request->getParsedBody();
        // TODO: Implement login logic
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    });
    
    // Protected routes (require authentication)
    $app->group('', function ($group) use ($container) {
        // Dashboard
        $group->get('/dashboard', function (Request $request, Response $response) use ($container) {
            $user = $request->getAttribute('user');
            $view = $container->get('view');
            return $view->render($response, 'dashboard.html.twig', [
                'user' => $user
            ]);
        });
        
        // Admin routes
        $group->group('/admin', function ($adminGroup) use ($container) {
            $adminGroup->get('', function (Request $request, Response $response) use ($container) {
                $user = $request->getAttribute('user');
                $view = $container->get('view');
                return $view->render($response, 'admin/dashboard.html.twig', [
                    'user' => $user
                ]);
            });
        });
        
    })->add($authMiddleware);
    
    // API routes
    $app->group('/api', function ($group) use ($container) {
        // API routes here
    })->add($authMiddleware);
    
    // Catch-all 404 handler
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response) {
        $response->getBody()->write('Page not found');
        return $response->withStatus(404);
    });
};
