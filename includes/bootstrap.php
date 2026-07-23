<?php
declare(strict_types=1);

/**
 * Bootstrap file - Loads all OOP classes and initializes the application.
 * Include this file in all pages and API endpoints.
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Timezone initialization
require_once __DIR__ . '/utils.php';

// Autoload classes from includes/classes/
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/classes/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
