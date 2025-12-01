<?php

use DI\Container;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use PDO;
use App\Services\ConfigService;
use App\Services\UtilityService;
use App\Services\AuthenticationService;
use App\Middleware\AuthenticationMiddleware;

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    // PDO Database Connection
    PDO::class => function (ContainerInterface $c) {
        $config = ConfigService::get('database', []);
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            $config['host'] ?? 'localhost',
            $config['dbname'] ?? 'importer',
            $config['charset'] ?? 'utf8mb4'
        );
        
        $pdo = new PDO(
            $dsn,
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        
        return $pdo;
    },
    
    // ConfigService - create an instance wrapper for DI
    ConfigService::class => function () {
        return new ConfigService();
    },
    
    // UtilityService
    UtilityService::class => function (ContainerInterface $c) {
        return new UtilityService($c->get(ConfigService::class));
    },
    
    // AuthenticationService
    AuthenticationService::class => function (ContainerInterface $c) {
        return new AuthenticationService(
            $c->get(PDO::class),
            $c->get(ConfigService::class)
        );
    },
    
    // AuthenticationMiddleware
    AuthenticationMiddleware::class => function (ContainerInterface $c) {
        return new AuthenticationMiddleware(
            $c->get(AuthenticationService::class),
            $c->get(ConfigService::class)
        );
    },
]);

return $containerBuilder->build();

