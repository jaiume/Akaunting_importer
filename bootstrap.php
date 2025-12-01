<?php

// Define BASE_DIR constant before loading ConfigService
define('BASE_DIR', dirname(__DIR__));

// Autoload dependencies
require_once BASE_DIR . '/vendor/autoload.php';

// Load environment variables if .env exists
if (file_exists(BASE_DIR . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_DIR);
    $dotenv->load();
}

// Set timezone
date_default_timezone_set(\App\Services\ConfigService::get('app.timezone', 'UTC'));

// Error reporting
$debug = \App\Services\ConfigService::get('app.debug', false);
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

