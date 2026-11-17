<?php
declare(strict_types=1);
/**
 * Setup API - Platform Super Admin installation
 * 
 * POST /API/Setup/index.php
 *   - Create the platform Super Admin account
 * 
 * GET /API/Setup/index.php
 *   - Check if setup is complete
 */

require_once __DIR__ . '/../../includes/utils.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// If the db config file is missing, the system cannot be set up yet.
if (!db_config_exists()) {
    json_response([
        'success' => false,
        'data' => [
            'setup_complete' => false,
            'setup_required' => true,
            'db_configured' => false,
            'redirect' => '/Setup/index.php',
        ],
        'error' => 'Database configuration file not found. Please complete the Database Setup step first.',
        'meta' => null,
    ], 503);
}

require_once __DIR__ . '/../../includes/db.php';

try {
    $pdo = get_db();
} catch (Throwable $e) {
    // Database exists but connection failed - cannot complete setup yet.
    json_response([
        'success' => false,
        'data' => [
            'setup_complete' => false,
            'setup_required' => true,
            'db_configured' => true,
            'db_connected' => false,
            'redirect' => '/Setup/index.php',
        ],
        'error' => 'Database connection failed: ' . $e->getMessage(),
        'meta' => null,
    ], 503);
}

// ============================================================
// GET - Check setup status
// ============================================================
if ($method === 'GET') {
    $setupComplete = is_setup_complete($pdo);
    
    json_response([
        'success' => true,
        'data' => [
            'setup_complete' => $setupComplete,
            'setup_required' => !$setupComplete,
            'db_configured' => true,
        ],
        'error' => null,
        'meta' => null,
    ]);
    return;
}

// ============================================================
// POST - Install the platform Super Admin account
// ============================================================
if ($method !== 'POST') {
    http_response_code(405);
    json_response(['error' => 'Method not allowed'], 405);
}

// If setup is already complete, prevent re-running
if (is_setup_complete($pdo)) {
    json_response(['error' => 'Setup is already complete. The platform Super Admin already exists.'], 409);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$passwordConfirm = (string)($body['password_confirm'] ?? '');
// Validation
if ($name === '' || $email === '' || $password === '') {
    json_response(['error' => 'Name, email and password are required'], 400);
}

if ($password !== $passwordConfirm) {
    json_response(['error' => 'Passwords do not match'], 400);
}

if (!is_strong_password($password)) {
    json_response(['error' => 'Password must be at least 12 characters and include uppercase and lowercase letters, a number, and a symbol'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Invalid email address'], 400);
}

// Check if email already exists
$checkStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
$checkStmt->execute([':email' => $email]);
if ($checkStmt->fetch()) {
    json_response(['error' => 'A user with this email already exists'], 409);
}

try {
    $pdo->beginTransaction();
    
    $now = date('Y-m-d H:i:s');
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // The setup account is the single platform-level administrator.
    $role = 'super_admin';
    $restaurantId = null;
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, restaurant_id, created_at)
         VALUES (:name, :email, :password_hash, :role, :restaurant_id, :created_at)'
    );
    $insertStmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => $role,
        ':restaurant_id' => $restaurantId,
        ':created_at' => $now,
    ]);
    
    $userId = (int)$pdo->lastInsertId();
    $superAdminSetting = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:key, :value, :updated_at)'
    );
    $superAdminSetting->execute([
        ':key' => 'platform_super_admin_id',
        ':value' => (string)$userId,
        ':updated_at' => $now,
    ]);
    
    // Set setup_complete flag in settings
    $settingStmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at) 
         VALUES (:key, :value, :updated_at) 
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
    );
    $settingStmt->execute([
        ':key' => 'setup_complete',
        ':value' => '1',
        ':updated_at' => $now,
    ]);
    
    // Auto-create default settings if they don't exist
    $defaultSettings = [
        ['restaurant_name', 'Restaurant POS'],
        ['logo_url', '/assets/images/brainyte-icon.png'],
        ['vat_rate', '0.00'],
        ['currency', 'NGN'],
        ['timezone', 'Africa/Lagos'],
        ['printer_type', 'thermal'],
        ['footer_text', 'Powered by Brainyte'],
        ['direct_printing', '0'],
    ];
    $defaultStmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
    foreach ($defaultSettings as $setting) {
        $defaultStmt->execute([':key' => $setting[0], ':value' => $setting[1], ':updated_at' => $now]);
    }
    
    $pdo->commit();
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        json_response(['success' => false, 'error' => 'An account with this email already exists or platform setup has already completed'], 409);
    }
    error_log('Setup error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Unable to complete setup'], 500);
}

json_response([
    'success' => true,
    'data' => [
        'message' => 'Setup complete. You can now log in.',
        'user_id' => $userId,
        'role' => $role,
        'redirect' => '/Login/index.php',
    ],
    'error' => null,
    'meta' => null,
]);
