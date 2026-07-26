<?php
declare(strict_types=1);
/**
 * API v1 - Authentication
 *
 * POST /API/v1/auth/index.php - Login
 *   Body: { email, password, device_name?: string }
 *   Response: { access_token, refresh_token, token_type, expires_in, refresh_expires_in, user }
 *
 * This is the stable public API for the Flutter application.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Auth;
use App\AuditLog;
use App\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');
$deviceName = trim((string)($body['device_name'] ?? ''));

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

$pdo = get_db();
$auth = new Auth($pdo);
$auditLog = new AuditLog($pdo);
$rateLimiter = new RateLimiter($pdo);

// Rate limiting
$clientIp = RateLimiter::getClientIp();
$rateCheck = $rateLimiter->checkLogin($clientIp . '|' . $email);
if (!$rateCheck['allowed']) {
    $auditLog->log(null, 'login_throttled', 'auth', null, "Login throttled for {$email} from {$clientIp}");
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Please try again later.']);
    exit;
}

// Authenticate
$user = $auth->login($email, $password);

if (empty($user)) {
    $rateLimiter->recordLoginAttempt($clientIp . '|' . $email, false);
    $auditLog->log(null, 'login_failed', 'auth', null, "Failed v1 login attempt for {$email} from {$clientIp}");
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// Record successful login
$rateLimiter->recordLoginAttempt($clientIp . '|' . $email, true);
$auditLog->login($user['id'], true, 'v1_api');

// Generate token pair for Flutter
$tokens = $auth->generateTokenPair($user['id'], $deviceName);

echo json_encode([
    'success' => true,
    'data' => [
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
    ],
    'error' => null,
    'meta' => null,
]);
