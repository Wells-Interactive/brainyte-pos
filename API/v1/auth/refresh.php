<?php
declare(strict_types=1);
/**
 * API v1 - Token Refresh
 *
 * POST /API/v1/auth/refresh.php
 *   Body: { refresh_token, device_name?: string }
 *   Response: { access_token, refresh_token, expires_in, refresh_expires_in, user }
 *
 * Implements token rotation for security.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

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

$refreshToken = trim((string)($body['refresh_token'] ?? ''));
$deviceName = trim((string)($body['device_name'] ?? ''));

if ($refreshToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Refresh token is required']);
    exit;
}

$pdo = get_db();
$auth = new App\Auth($pdo);

$result = $auth->validateRefreshToken($refreshToken, $deviceName);

if ($result === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired refresh token']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'access_token' => $result['tokens']['access_token'],
        'refresh_token' => $result['tokens']['refresh_token'],
        'token_type' => 'Bearer',
        'expires_in' => $result['tokens']['expires_in'],
        'refresh_expires_in' => $result['tokens']['refresh_expires_in'],
        'user' => $result['user'],
    ],
    'error' => null,
    'meta' => null,
]);
