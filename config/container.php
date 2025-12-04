<?php

use DI\Container;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use PDO;

// Services
use App\Services\ConfigService;
use App\Services\UtilityService;
use App\Services\AuthenticationService;
use App\Services\EntityService;
use App\Services\AccountService;
use App\Services\ImportService;
use App\Services\InstallationService;
use App\Services\AccountLinkService;
use App\Services\TransactionMatchingService;

// DAOs
use App\DAO\EntityDAO;
use App\DAO\AccountDAO;
use App\DAO\BatchDAO;
use App\DAO\TransactionDAO;
use App\DAO\VendorDAO;
use App\DAO\InstallationDAO;
use App\DAO\MatchJobDAO;

// Controllers
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\SettingsController;
use App\Controllers\EntityController;
use App\Controllers\AccountController;
use App\Controllers\InstallationController;
use App\Controllers\ImportController;
use App\Controllers\ApiController;

// Middleware
use App\Middleware\AuthenticationMiddleware;

// Processors
use App\Processors\ProcessorFactory;

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    // ===================
    // Core Dependencies
    // ===================
    
    // PDO Database Connection
    PDO::class => function (ContainerInterface $c) {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            ConfigService::get('database.host', 'localhost'),
            ConfigService::get('database.dbname', 'Akaunting_importer'),
            ConfigService::get('database.charset', 'utf8mb4')
        );
        
        return new PDO(
            $dsn,
            ConfigService::get('database.username', 'Akaunting_importer'),
            ConfigService::get('database.password', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    },
    
    // ConfigService
    ConfigService::class => function () {
        return new ConfigService();
    },
    
    // ===================
    // DAOs
    // ===================
    
    EntityDAO::class => function (ContainerInterface $c) {
        return new EntityDAO($c->get(PDO::class));
    },
    
    AccountDAO::class => function (ContainerInterface $c) {
        return new AccountDAO($c->get(PDO::class));
    },
    
    BatchDAO::class => function (ContainerInterface $c) {
        return new BatchDAO($c->get(PDO::class));
    },
    
    TransactionDAO::class => function (ContainerInterface $c) {
        return new TransactionDAO($c->get(PDO::class));
    },
    
    VendorDAO::class => function (ContainerInterface $c) {
        return new VendorDAO($c->get(PDO::class));
    },
    
    InstallationDAO::class => function (ContainerInterface $c) {
        return new InstallationDAO($c->get(PDO::class));
    },
    
    // ===================
    // Processors
    // ===================
    
    ProcessorFactory::class => function (ContainerInterface $c) {
        return new ProcessorFactory($c->get(PDO::class));
    },
    
    // ===================
    // Services
    // ===================
    
    UtilityService::class => function (ContainerInterface $c) {
        return new UtilityService($c->get(ConfigService::class));
    },
    
    AuthenticationService::class => function (ContainerInterface $c) {
        return new AuthenticationService(
            $c->get(PDO::class),
            $c->get(ConfigService::class),
            $c->get(UtilityService::class)
        );
    },
    
    EntityService::class => function (ContainerInterface $c) {
        return new EntityService(
            $c->get(EntityDAO::class),
            $c->get(AccountDAO::class),
            $c->get(InstallationDAO::class)
        );
    },
    
    AccountService::class => function (ContainerInterface $c) {
        return new AccountService(
            $c->get(AccountDAO::class),
            $c->get(EntityDAO::class)
        );
    },
    
    InstallationService::class => function (ContainerInterface $c) {
        return new InstallationService($c->get(InstallationDAO::class));
    },
    
    AccountLinkService::class => function (ContainerInterface $c) {
        return new AccountLinkService(
            $c->get(AccountDAO::class),
            $c->get(InstallationDAO::class),
            $c->get(InstallationService::class)
        );
    },
    
    ImportService::class => function (ContainerInterface $c) {
        $uploadDir = BASE_DIR . '/' . ConfigService::get('paths.uploads_dir', 'public/uploads');
        return new ImportService(
            $c->get(BatchDAO::class),
            $c->get(TransactionDAO::class),
            $c->get(MatchJobDAO::class),
            $c->get(ProcessorFactory::class),
            $uploadDir
        );
    },
    
    MatchJobDAO::class => function (ContainerInterface $c) {
        return new MatchJobDAO($c->get(PDO::class));
    },
    
    TransactionMatchingService::class => function (ContainerInterface $c) {
        $matchingWindowDays = (int)ConfigService::get('akaunting.matching_window_days', 5);
        return new TransactionMatchingService(
            $c->get(BatchDAO::class),
            $c->get(TransactionDAO::class),
            $c->get(AccountDAO::class),
            $c->get(InstallationDAO::class),
            $c->get(InstallationService::class),
            $c->get(MatchJobDAO::class),
            $matchingWindowDays
        );
    },
    
    // ===================
    // Middleware
    // ===================
    
    AuthenticationMiddleware::class => function (ContainerInterface $c) {
        return new AuthenticationMiddleware(
            $c->get(AuthenticationService::class),
            $c->get(ConfigService::class)
        );
    },
    
    // ===================
    // Controllers
    // ===================
    
    AuthController::class => function (ContainerInterface $c) {
        return new AuthController(
            $c->get('view'),
            $c->get(AuthenticationService::class),
            $c->get(ConfigService::class)
        );
    },
    
    DashboardController::class => function (ContainerInterface $c) {
        return new DashboardController(
            $c->get('view'),
            $c->get(BatchDAO::class),
            $c->get(AuthenticationService::class),
            $c->get(ConfigService::class)
        );
    },
    
    SettingsController::class => function (ContainerInterface $c) {
        return new SettingsController($c->get('view'));
    },
    
    EntityController::class => function (ContainerInterface $c) {
        return new EntityController(
            $c->get('view'),
            $c->get(EntityService::class)
        );
    },
    
    ImportController::class => function (ContainerInterface $c) {
        return new ImportController(
            $c->get('view'),
            $c->get(ImportService::class),
            $c->get(EntityService::class),
            $c->get(TransactionMatchingService::class),
            $c->get(TransactionDAO::class),
            $c->get(VendorDAO::class),
            $c->get(InstallationService::class)
        );
    },
    
    ApiController::class => function (ContainerInterface $c) {
        return new ApiController(
            $c->get('view'),
            $c->get(AccountService::class),
            $c->get(AccountLinkService::class),
            $c->get(InstallationService::class)
        );
    },
    
    AccountController::class => function (ContainerInterface $c) {
        return new AccountController(
            $c->get('view'),
            $c->get(AccountService::class)
        );
    },
    
    InstallationController::class => function (ContainerInterface $c) {
        return new InstallationController(
            $c->get('view'),
            $c->get(InstallationService::class),
            $c->get(EntityService::class)
        );
    },
]);

return $containerBuilder->build();
