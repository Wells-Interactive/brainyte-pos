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

// ============================================================
// Automatic Setup Detection
// ============================================================
// If includes/db.php has been deleted, automatically redirect
// the user to the Setup wizard.
//
// The Setup directory is excluded so Setup can create db.php.
// ============================================================

$dbConfigFile = __DIR__ . '/db.php';

if (!file_exists($dbConfigFile)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

    // Allow Setup to run when db.php is missing.
    $isSetupRequest =
        $requestPath === '/Setup' ||
        $requestPath === '/Setup/' ||
        str_starts_with($requestPath, '/Setup/');

    if (!$isSetupRequest) {
        header('Location: /Setup/index.php');
        exit;
    }
}

// ============================================================
// Autoload classes from includes/classes/
// ============================================================

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
