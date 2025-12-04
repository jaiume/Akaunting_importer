<?php

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

return function (App $app) {
    $container = $app->getContainer();
    $authMiddleware = $container->get(AuthenticationMiddleware::class);

    // ===================
    // Public Routes
    // ===================
    
    // Home redirect
    $app->get('/', [DashboardController::class, 'home']);
    
    // Authentication routes
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/login/token/{token}', [AuthController::class, 'verifyToken']);
    $app->get('/logout', [AuthController::class, 'logout']);

    // ===================
    // Protected Routes
    // ===================
    
    $app->group('', function ($group) {
        
        // Dashboard
        $group->get('/dashboard', [DashboardController::class, 'index']);
        
        // Settings
        $group->get('/settings', [SettingsController::class, 'index']);
        
        // Entity Management (with accounts)
        $group->get('/settings/entities', [EntityController::class, 'index']);
        $group->get('/settings/entities/create', [EntityController::class, 'showCreate']);
        $group->post('/settings/entities/create', [EntityController::class, 'create']);
        $group->get('/settings/entities/{entity_id}/edit', [EntityController::class, 'showEdit']);
        $group->post('/settings/entities/{entity_id}/edit', [EntityController::class, 'update']);
        $group->post('/settings/entities/{entity_id}/delete', [EntityController::class, 'delete']);
        
        // Add account for specific entity
        $group->get('/settings/entities/{entity_id}/accounts/create', [AccountController::class, 'showCreateForEntity']);
        $group->post('/settings/entities/{entity_id}/accounts/create', [AccountController::class, 'createForEntity']);
        
        // Add installation for specific entity
        $group->get('/settings/entities/{entity_id}/installations/create', [InstallationController::class, 'showCreateForEntity']);
        $group->post('/settings/entities/{entity_id}/installations/create', [InstallationController::class, 'createForEntity']);
        
        // Account Management (standalone - redirects to entities page)
        $group->get('/settings/accounts', [AccountController::class, 'index']);
        $group->get('/settings/accounts/create', [AccountController::class, 'showCreate']);
        $group->post('/settings/accounts/create', [AccountController::class, 'create']);
        $group->get('/settings/accounts/{account_id}/edit', [AccountController::class, 'showEdit']);
        $group->post('/settings/accounts/{account_id}/edit', [AccountController::class, 'update']);
        $group->post('/settings/accounts/{account_id}/delete', [AccountController::class, 'delete']);
        
        // Akaunting Installation Management (standalone routes redirect to entities)
        $group->get('/settings/installations', [InstallationController::class, 'index']);
        $group->get('/settings/installations/create', [InstallationController::class, 'showCreate']);
        $group->post('/settings/installations/create', [InstallationController::class, 'create']);
        $group->get('/settings/installations/{installation_id}/edit', [InstallationController::class, 'showEdit']);
        $group->post('/settings/installations/{installation_id}/edit', [InstallationController::class, 'update']);
        $group->post('/settings/installations/{installation_id}/delete', [InstallationController::class, 'delete']);
        $group->post('/settings/installations/{installation_id}/test', [InstallationController::class, 'testConnection']);
        
        // Import
        $group->get('/import', [ImportController::class, 'showForm']);
        $group->post('/import', [ImportController::class, 'import']);
        $group->get('/import/batch/{batch_id}', [ImportController::class, 'showBatch']);
        $group->post('/import/batch/{batch_id}/process', [ImportController::class, 'processBatch']);
        $group->post('/import/batch/{batch_id}/match', [ImportController::class, 'matchBatch']);
        $group->post('/import/batch/{batch_id}/clear-matches', [ImportController::class, 'clearMatches']);
        $group->post('/import/batch/{batch_id}/match-progress', [ImportController::class, 'matchProgress']);
        $group->post('/import/batch/{batch_id}/match-reset', [ImportController::class, 'matchReset']);
        $group->post('/import/batch/{batch_id}/reimport', [ImportController::class, 'reimportBatch']);
        $group->post('/import/batch/{batch_id}/push-transaction', [ImportController::class, 'pushTransaction']);
        $group->post('/import/batch/{batch_id}/replicate-transaction', [ImportController::class, 'replicateTransaction']);
        $group->get('/import/batch/{batch_id}/vendors', [ImportController::class, 'getVendors']);
        $group->post('/import/batch/{batch_id}/delete', [ImportController::class, 'deleteBatch']);
        $group->post('/import/batch/{batch_id}/archive', [ImportController::class, 'archiveBatch']);
        $group->post('/import/batch/{batch_id}/unarchive', [ImportController::class, 'unarchiveBatch']);
        
    })->add($authMiddleware);

    // ===================
    // API Routes
    // ===================
    
    $app->group('/api', function ($group) {
        // Get local accounts by entity
        $group->get('/accounts/{entity_name}', [ApiController::class, 'getAccountsByEntity']);
        
        // Akaunting integration
        $group->get('/installations/{installation_id}/akaunting-accounts', [ApiController::class, 'getAkauntingAccounts']);
        $group->get('/installations/{installation_id}/form-data', [ApiController::class, 'getInstallationFormData']);
        
        // Get entities with installations (for cross-entity replication)
        $group->get('/entities-with-installations', [ApiController::class, 'getEntitiesWithInstallations']);
        
        // Account linking (links stored directly on accounts table)
        $group->get('/accounts/{account_id}/link', [ApiController::class, 'getAccountLinks']);
        $group->post('/accounts/{account_id}/link', [ApiController::class, 'saveAccountLink']);
        $group->delete('/accounts/{account_id}/link', [ApiController::class, 'deleteAccountLink']);
        
        // File analysis for smart import
        $group->post('/analyze-file', [ApiController::class, 'analyzeFile']);
    })->add($authMiddleware);

    // ===================
    // 404 Handler
    // ===================
    
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response) {
        $response->getBody()->write('Page not found');
        return $response->withStatus(404);
    });
};
