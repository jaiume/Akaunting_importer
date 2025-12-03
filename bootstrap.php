<?php

// Define BASE_DIR constant before loading ConfigService
define('BASE_DIR', __DIR__);

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
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Set error handler to log errors instead of displaying them in production
if (!$debug) {
    set_error_handler(function($severity, $message, $file, $line) {
        error_log("PHP Error [$severity]: $message in $file on line $line");
        return false; // Let PHP handle the error normally
    });
}

