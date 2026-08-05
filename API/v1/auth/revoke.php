<?php
declare(strict_types=1);
/**
 * API v1 - Token Revocation
 *
 * POST /API/v1/auth/revoke.php - Revoke tokens
 *   Body: { token_id?: int, refresh_token?: string, revoke_all?: bool, user_id?: int }
 *
 * GET  /API/v1/auth/revoke.php?list_sessions=1 - List active sessions
 *
 * Requires authentication via Bearer token.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET - List active sessions
// ============================================================
if ($method === 'GET') {
    if (!isset($_GET['list_sessions'])) {
        json_response(['error' => 'Method not allowed'], 405);
    }

    $authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
    $userId = $authUser['id'];

    $sessions = list_user_sessions($pdo, $userId);
    json_response([
        'success' => true,
        'data' => $sessions,
        'meta' => ['count' => count($sessions)],
    ]);
}

// ============================================================
// POST - Revoke tokens
// ============================================================
if ($method !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

// Revoke by refresh_token (allows logout even when access token is expired)
$refreshToken = trim((string)($body['refresh_token'] ?? ''));
if ($refreshToken !== '') {
    if (!revoke_refresh_token($pdo, $refreshToken)) {
        json_response(['error' => 'Refresh token not found or already revoked'], 404);
    }

    json_response([
        'success' => true,
        'data' => ['message' => 'Refresh token revoked successfully'],
    ]);
}

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
$userId = $authUser['id'];
$role = $authUser['role'];

// Revoke all sessions for a user (admin/owner only)
if (!empty($body['revoke_all'])) {
    if (!in_array($role, ['admin', 'owner'], true)) {
        json_response(['error' => 'Forbidden: only admins can revoke all sessions'], 403);
    }

    $targetUserId = isset($body['user_id']) ? (int)$body['user_id'] : $userId;
    revoke_all_user_tokens($pdo, $targetUserId);

    json_response([
        'success' => true,
        'data' => ['message' => 'All active sessions have been revoked'],
    ]);
}

// Revoke by token_id
if (isset($body['token_id'])) {
    $tokenId = (int)$body['token_id'];
    if ($tokenId <= 0) {
        json_response(['error' => 'Invalid token ID'], 400);
    }

    $revoked = revoke_user_session($pdo, $tokenId, $userId);
    if (!$revoked) {
        json_response(['error' => 'Session not found or already revoked'], 404);
    }

    json_response([
        'success' => true,
        'data' => ['message' => 'Session revoked successfully'],
    ]);
}

// Revoke by access token
$accessToken = trim((string)($body['token'] ?? ''));
if ($accessToken !== '') {
    $stmt = $pdo->prepare(
        'UPDATE auth_tokens SET revoked = 1 WHERE token = :token AND user_id = :user_id'
    );
    $stmt->execute([':token' => $accessToken, ':user_id' => $userId]);

    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Token not found or already revoked'], 404);
    }

    json_response([
        'success' => true,
        'data' => ['message' => 'Access token revoked successfully'],
    ]);
}

json_response(['error' => 'No token provided. Provide token_id, token, refresh_token, or revoke_all.'], 400);
