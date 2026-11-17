<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\Auth;
use App\CSRF;
use App\AuditLog;
use App\RateLimiter;
use App\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_response(['error' => 'Method not allowed'], 405);
}

$pdo = get_db();
$auth = new Auth($pdo);
$auditLog = new AuditLog($pdo);
$rateLimiter = new RateLimiter($pdo);

$action = trim((string)($_GET['action'] ?? ''));

// ============================================================
// Handle add_user action for admin/owner
// ============================================================
if ($action === 'add_user') {
    $creatingUser = require_role(['admin', 'owner']);
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $name = trim((string)($body['name'] ?? ''));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');
    $role = trim((string)($body['role'] ?? ''));

    if ($name === '' || $email === '' || $password === '' || !in_array($role, ['waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'customer', 'rider'], true)) {
        json_response(['error' => 'Name, email, password and valid role are required'], 400);
    }

    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        json_response(['error' => 'A user with this email already exists'], 409);
    }

    $now = date('Y-m-d H:i:s');
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $restaurantId = (int)($creatingUser['restaurant_id'] ?? 0);
    if ($restaurantId <= 0) {
        $restaurantId = (int)$pdo->query('SELECT id FROM restaurants ORDER BY id LIMIT 1')->fetchColumn();
    }
    try {
        $insertStmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, restaurant_id, created_at) VALUES (:name, :email, :password_hash, :role, :restaurant_id, :created_at)');
        $insertStmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':restaurant_id' => $restaurantId ?: null,
            ':created_at' => $now,
        ]);
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() === '23000') {
            json_response(['error' => 'A user with this email already exists'], 409);
        }
        throw $exception;
    }

    $userId = (int)$pdo->lastInsertId();
    $auditLog->dataChange(0, 'user', $userId, 'create', "Admin created user {$name} ({$email}) with role {$role}");

    json_response(['success' => true, 'user_id' => $userId]);
    exit;
}

// ============================================================
// Standard Login with Rate Limiting
// ============================================================
try {
    $body = get_json_body();
} catch (JsonException $exception) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(['error' => 'Email and password are required'], 400);
}

// Check rate limiting
$clientIp = RateLimiter::getClientIp();
$rateCheck = $rateLimiter->checkLogin($clientIp . '|' . $email);

if (!$rateCheck['allowed']) {
    $auditLog->log(null, 'login_throttled', 'auth', null, "Login throttled for {$email} from {$clientIp}");
    json_response(['error' => 'Too many login attempts. Please try again later.'], 429);
}

// Attempt login
$user = $auth->login($email, $password);

if (empty($user)) {
    $rateLimiter->recordLoginAttempt($clientIp . '|' . $email, false);
    $auditLog->log(null, 'login_failed', 'auth', null, "Failed login attempt for {$email} from {$clientIp}");
    json_response(['error' => 'Invalid credentials'], 401);
}

if (!empty($user['is_demo']) && !is_demo_mode_enabled($pdo)) {
    $rateLimiter->recordLoginAttempt($clientIp . '|' . $email, false);
    json_response(['error' => 'Demo mode is currently disabled'], 403);
}

// Successful login
$rateLimiter->recordLoginAttempt($clientIp . '|' . $email, true);

// Create session
$auth->createSession($user);

// Audit log
$auditLog->login($user['id'], true);

// Generate CSRF token for session
$csrfToken = CSRF::generate();

// ============================================================
// Token pair for Flutter (if requested)
// ============================================================
$tokens = null;
$requestToken = (bool)($body['request_token'] ?? false);
if ($requestToken) {
    $deviceName = trim((string)($body['device_name'] ?? ''));
    $tokens = $auth->generateTokenPair($user['id'], $deviceName);
}

// ============================================================
// Standardized Response with auto-redirect info
// ============================================================
$redirectMap = [
    'waiter' => '/Waiter/index.php',
    'kitchen' => '/Kitchen/index.php',
    'bar' => '/Bar/index.php',
    'manager' => '/Manager/index.php',
    'supervisor' => '/Manager/index.php',
    'admin' => '/index.php',
    'owner' => '/index.php',
    'super_admin' => '/SuperAdmin/index.php',
];

$response = [
    'success' => true,
    'data' => [
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_demo' => (bool)$user['is_demo'],
            'restaurant_id' => $user['restaurant_id'],
        ],
        'csrf_token' => $csrfToken,
        'redirect' => $redirectMap[$user['role']] ?? '/index.php',
    ],
    'error' => null,
    'meta' => null,
];

if ($tokens) {
    $response['data']['access_token'] = $tokens['access_token'];
    $response['data']['refresh_token'] = $tokens['refresh_token'];
    $response['data']['token_type'] = 'Bearer';
    $response['data']['expires_in'] = $tokens['expires_in'];
    $response['data']['refresh_expires_in'] = $tokens['refresh_expires_in'];
}

json_response($response);
