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
$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
$userId = $authUser['id'];
$role = $authUser['role'];

// ============================================================
// GET - List active sessions
// ============================================================
if ($method === 'GET') {
    if (!isset($_GET['list_sessions'])) {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $sessions = list_user_sessions($pdo, $userId);
    echo json_encode([
        'success' => true,
        'data' => $sessions,
        'meta' => ['count' => count($sessions)],
    ]);
    exit;
}

// ============================================================
// POST - Revoke tokens
// ============================================================
if ($method !== 'POST') {
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

// Revoke all sessions for a user (admin/owner only)
if (!empty($body['revoke_all'])) {
    if (!in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: only admins can revoke all sessions']);
        exit;
    }

    $targetUserId = isset($body['user_id']) ? (int)$body['user_id'] : $userId;
    revoke_all_user_tokens($pdo, $targetUserId);

    echo json_encode([
        'success' => true,
        'data' => ['message' => 'All active sessions have been revoked'],
    ]);
    exit;
}

// Revoke by token_id
if (isset($body['token_id'])) {
    $tokenId = (int)$body['token_id'];
    if ($tokenId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid token ID']);
        exit;
    }

    $revoked = revoke_user_session($pdo, $tokenId, $userId);
    if (!$revoked) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found or already revoked']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => ['message' => 'Session revoked successfully'],
    ]);
    exit;
}

// Revoke by refresh_token
$refreshToken = trim((string)($body['refresh_token'] ?? ''));
if ($refreshToken !== '') {
    $stmt = $pdo->prepare(
        'UPDATE auth_tokens SET revoked = 1 WHERE refresh_token = :refresh_token AND user_id = :user_id'
    );
    $stmt->execute([':refresh_token' => $refreshToken, ':user_id' => $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Refresh token not found or already revoked']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => ['message' => 'Refresh token revoked successfully'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'No token provided. Provide token_id, refresh_token, or revoke_all.']);
