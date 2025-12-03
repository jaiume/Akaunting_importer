<?php

// Load bootstrap
require_once dirname(__DIR__) . '/bootstrap.php';

// Create DI container
$container = require BASE_DIR . '/config/container.php';

// Create Slim app with DI container
$app = \DI\Bridge\Slim\Bridge::create($container);

// Add RoutingMiddleware to enable route parameter access
$app->addRoutingMiddleware();

// Register Twig
$container->set('view', function() use ($container) {
    $loader = new \Twig\Loader\FilesystemLoader(BASE_DIR . '/templates');
    $twig = new \Slim\Views\Twig($loader, [
        'cache' => false, // Set to BASE_DIR . '/cache' in production
        'debug' => \App\Services\ConfigService::get('app.debug', false),
        'auto_reload' => true,
    ]);
    
    // Add global variables
    $utilityService = $container->get(\App\Services\UtilityService::class);
    $twig->getEnvironment()->addGlobal('base_url', $utilityService->getBaseUrl());
    
    return $twig;
});

// Load routes
$routes = require BASE_DIR . '/config/routes.php';
$routes($app);

// Run the app
$app->run();

