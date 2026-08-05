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
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$refreshToken = trim((string)($body['refresh_token'] ?? ''));
$deviceName = trim((string)($body['device_name'] ?? ''));

if ($refreshToken === '') {
    json_response(['error' => 'Refresh token is required'], 400);
}

$pdo = get_db();
$auth = new App\Auth($pdo);

$result = $auth->validateRefreshToken($refreshToken, $deviceName);

if ($result === null) {
    json_response(['error' => 'Invalid or expired refresh token'], 401);
}

json_response([
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
