<?php
declare(strict_types=1);
/**
 * Token Revocation Endpoint
 * 
 * POST /API/Auth/revoke.php
 * 
 * Revokes tokens. Supports:
 * - Revoke a single access token (pass in Authorization header)
 * - Revoke a refresh token (pass in body)
 * - Revoke a specific session by token_id (admin/owner only)
 * - Revoke all sessions for a user (admin/owner only)
 * - List active sessions (authenticated user)
 * 
 * Body: { token_id?: int, refresh_token?: string, revoke_all?: bool }
 * 
 * GET  /API/Auth/revoke.php?list_sessions=1 - List active sessions for current user
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET - List active sessions
// ============================================================
if ($method === 'GET' && isset($_GET['list_sessions'])) {
    $authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
    
    $sessions = list_user_sessions($pdo, $authUser['id']);
    
    json_response([
        'success' => true,
        'data' => $sessions,
        'meta' => ['count' => count($sessions)],
    ]);
    return;
}

// ============================================================
// POST - Revoke tokens
// ============================================================
if ($method !== 'POST') {
    http_response_code(405);
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

// Authenticate the user
$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
$userId = $authUser['id'];
$role = $authUser['role'];

// ============================================================
// Revoke All Sessions (admin/owner only)
// ============================================================
if (!empty($body['revoke_all'])) {
    if (!in_array($role, ['admin', 'owner'], true)) {
        json_response(['error' => 'Only admins can revoke all sessions'], 403);
    }
    
    $targetUserId = isset($body['user_id']) ? (int)$body['user_id'] : $userId;
    revoke_all_user_tokens($pdo, $targetUserId);
    
    json_response([
        'success' => true,
        'data' => ['message' => 'All active sessions have been revoked'],
    ]);
    return;
}

// ============================================================
// Revoke by token_id (session revocation)
// ============================================================
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
    return;
}

// ============================================================
// Revoke by refresh_token
// ============================================================
$refreshToken = trim((string)($body['refresh_token'] ?? ''));
if ($refreshToken !== '') {
    // Find and revoke the token pair by refresh_token
    $stmt = $pdo->prepare(
        'UPDATE auth_tokens SET revoked = 1 WHERE refresh_token = :refresh_token AND user_id = :user_id'
    );
    $stmt->execute([':refresh_token' => $refreshToken, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        json_response(['error' => 'Refresh token not found or already revoked'], 404);
    }
    
    json_response([
        'success' => true,
        'data' => ['message' => 'Refresh token revoked successfully'],
    ]);
    return;
}

// ============================================================
// Revoke current access token (from Authorization header)
// ============================================================
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $accessToken = $matches[1];
    revoke_auth_token($pdo, $accessToken);
    
    json_response([
        'success' => true,
        'data' => ['message' => 'Access token revoked successfully'],
    ]);
    return;
}

json_response(['error' => 'No token provided to revoke. Provide token_id, refresh_token, or Bearer token in Authorization header.'], 400);
