<?php
declare(strict_types=1);
/**
 * Refresh Token Endpoint
 * 
 * POST /API/Auth/refresh.php
 * 
 * Exchanges a valid refresh token for a new access + refresh token pair.
 * Implements token rotation: the old refresh token is revoked after use.
 * 
 * Body: { refresh_token: string, device_name?: string }
 * 
 * Response: { access_token, refresh_token, expires_in, refresh_expires_in, user }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
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

// Validate the refresh token with rotation
$result = validate_refresh_token($pdo, $refreshToken, $deviceName);

if ($result === null) {
    // The refresh token might also be valid as a legacy token
    // Check if it's a valid auth token before denying
    $legacyCheck = validate_auth_token($pdo, $refreshToken);
    if ($legacyCheck) {
        // Legacy token - still valid but should migrate to new system
        // Generate new token pair and revoke old legacy token
        $userId = (int)$legacyCheck['id'];
        revoke_auth_token($pdo, $refreshToken);
        $tokens = generate_token_pair($pdo, $userId, $deviceName);
        $result = [
            'user' => $legacyCheck,
            'tokens' => $tokens,
        ];
    }
}

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
