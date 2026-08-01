<?php
declare(strict_types=1);

/**
 * Initialize timezone from database settings.
 * Falls back to 'Africa/Lagos' if settings table is not available.
 */
function init_timezone(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    // get_db() may not be defined yet when includes/db.php is missing.
    if (!function_exists('get_db')) {
        date_default_timezone_set('Africa/Lagos');
        return;
    }

    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'timezone' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        $tz = $row ? $row['setting_value'] : 'Africa/Lagos';
        date_default_timezone_set($tz);
    } catch (Throwable $e) {
        date_default_timezone_set('Africa/Lagos');
    }
}

/**
 * Check whether the database configuration file exists.
 */
function db_config_exists(): bool
{
    return file_exists(__DIR__ . '/db.php');
}

/**
 * Determine whether setup is still required.
 *
 * Returns true when:
 *   - includes/db.php is missing, OR
 *   - the database cannot be reached, OR
 *   - no admin/owner user exists yet.
 */
function setup_required(): bool
{
    if (!db_config_exists()) {
        return true;
    }

    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_db();
        return !is_setup_complete($pdo);
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Redirect non-API requests to the Setup wizard when setup is required.
 * API requests receive a JSON 503 response.
 */
function require_setup_or_redirect(): void
{
    if (!setup_required()) {
        return;
    }

    $isApi = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    if ($isApi) {
        http_response_code(503);
        json_response(['error' => 'System not set up. Please complete setup first.'], 503);
    }

    header('Location: /Setup/index.php');
    exit;
}

// Initialize timezone on every include
init_timezone();

/**
 * Get the current timestamp using PHP (not MySQL NOW()) as per requirements.
 */
function set_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * STANDARDIZED JSON RESPONSE
 * All API endpoints must use this function for consistent response format.
 *
 * Success: { "success": true, "data": {...}, "error": null, "meta": {...} }
 * Error:   { "success": false, "data": null, "error": "message", "meta": null }
 */
function json_response(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);

    // Normalize to standardized format
    $response = [
        'success' => $status >= 200 && $status < 300,
        'data' => null,
        'error' => null,
        'meta' => null,
    ];

    // If payload already uses standardized keys, pass through
    if (array_key_exists('success', $payload) && (array_key_exists('data', $payload) || array_key_exists('error', $payload))) {
        $response = $payload;
    } else {
        // Legacy format conversion
        if (isset($payload['error'])) {
            $response['success'] = false;
            $response['error'] = $payload['error'];
            $response['data'] = $payload['data'] ?? null;
        } elseif (isset($payload['success'])) {
            $response['success'] = (bool)$payload['success'];
            unset($payload['success']);
            $response['data'] = $payload;
        } else {
            $response['data'] = $payload;
        }
        if (isset($payload['meta'])) {
            $response['meta'] = $payload['meta'];
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse JSON request body.
 */
function get_json_body(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        return [];
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

/**
 * Escape output for safe HTML display (XSS prevention).
 */
function safe_text(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Require a logged-in session.
 */
function require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user']['role'])) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Require admin or owner role.
 */
function require_admin(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $role = $_SESSION['user']['role'] ?? null;
    if (empty($role) || !in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        json_response(['error' => 'Forbidden'], 403);
    }
}

/**
 * Require manager or above role.
 */
function require_manager(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $role = $_SESSION['user']['role'] ?? null;
    if (empty($role) || !in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true)) {
        http_response_code(403);
        json_response(['error' => 'Forbidden'], 403);
    }
}

/**
 * Check if current user is admin or owner.
 */
function is_admin_or_owner(): bool
{
    $role = $_SESSION['user']['role'] ?? null;
    return in_array($role, ['admin', 'owner'], true);
}

/**
 * Check if current user is manager or above.
 */
function is_manager_or_above(): bool
{
    $role = $_SESSION['user']['role'] ?? null;
    return in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true);
}

/**
 * Generate or retrieve CSRF token.
 */
function generate_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token.
 */
function verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token from request.
 */
function require_csrf_token(): void
{
    $body = get_json_body();
    $token = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(419);
        json_response(['error' => 'CSRF token mismatch'], 419);
    }
}

/**
 * Regenerate session ID after login (security).
 */
function regenerate_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $oldData = $_SESSION;
    session_regenerate_id(true);
    $_SESSION = $oldData;
}

// ============================================================
// SESSION-BASED AUTH HELPERS
// ============================================================

function get_session_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
}

function get_session_username(): string
{
    return trim((string)($_SESSION['username'] ?? $_SESSION['user']['name'] ?? ''));
}

function get_session_role(): string
{
    return trim((string)($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''));
}

// ============================================================
// BEARER TOKEN AUTH - ACCESS/REFRESH TOKEN PAIR (Flutter app)
// ============================================================

/**
 * Generate a token pair (access + refresh) for a user.
 *
 * Access token: short-lived (30 minutes)
 * Refresh token: long-lived (60 days)
 *
 * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int}
 */
function generate_token_pair(PDO $pdo, int $userId, string $deviceName = ''): array
{
    $now = date('Y-m-d H:i:s');
    $accessToken = bin2hex(random_bytes(32));
    $refreshToken = bin2hex(random_bytes(32));
    $accessExpiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $refreshExpiresAt = date('Y-m-d H:i:s', strtotime('+60 days'));

    // Ensure auth_tokens has the required columns
    _ensure_auth_token_columns($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, token, refresh_token, device_name, expires_at, refresh_expires_at, created_at) 
         VALUES (:user_id, :token, :refresh_token, :device_name, :expires_at, :refresh_expires_at, :created_at)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':token' => $accessToken,
        ':refresh_token' => $refreshToken,
        ':device_name' => $deviceName,
        ':expires_at' => $accessExpiresAt,
        ':refresh_expires_at' => $refreshExpiresAt,
        ':created_at' => $now,
    ]);

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => 1800, // 30 minutes in seconds
        'refresh_expires_in' => 5184000, // 60 days in seconds
    ];
}

/**
 * Ensure auth_tokens has columns required for token pair support.
 */
function _ensure_auth_token_columns(PDO $pdo): void
{
    _ensure_token_column($pdo, 'auth_tokens', 'refresh_token', "VARCHAR(64) DEFAULT NULL AFTER `token`");
    _ensure_token_column($pdo, 'auth_tokens', 'device_name', "VARCHAR(255) DEFAULT NULL");
    _ensure_token_column($pdo, 'auth_tokens', 'refresh_expires_at', "DATETIME DEFAULT NULL");
}

/**
 * Add a column to a table if it doesn't exist.
 */
function _ensure_token_column(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $pdo->quote($column)));
        if ($stmt->fetch()) {
            return;
        }
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    } catch (Throwable $e) {
        // Ignore errors
    }
}

/**
 * Validate an access token and return user data, or null if invalid.
 */
function validate_access_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.role, a.id AS token_id
         FROM auth_tokens a
         JOIN users u ON u.id = a.user_id
         WHERE a.token = :token AND a.revoked = 0 AND a.expires_at > :now
         LIMIT 1'
    );
    $stmt->execute([':token' => $token, ':now' => date('Y-m-d H:i:s')]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    // Update last_used_at
    $pdo->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE token = :token')
        ->execute([':now' => date('Y-m-d H:i:s'), ':token' => $token]);

    return [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'token_id' => (int)$row['token_id'],
    ];
}

/**
 * Validate a refresh token and return new token pair + user data.
 * Implements token rotation for security (old token is revoked).
 *
 * @return array{user: array, tokens: array}|null
 */
function validate_refresh_token(PDO $pdo, string $refreshToken, string $deviceName = ''): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.id AS token_id, a.user_id, u.id, u.name, u.email, u.role, a.device_name
         FROM auth_tokens a
         JOIN users u ON u.id = a.user_id
         WHERE a.refresh_token = :refresh_token 
           AND a.revoked = 0 
           AND a.refresh_expires_at > :now
         LIMIT 1'
    );
    $stmt->execute([':refresh_token' => $refreshToken, ':now' => date('Y-m-d H:i:s')]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $userId = (int)$row['user_id'];
    $deviceName = $deviceName ?: ($row['device_name'] ?? '');

    // Revoke the old token pair (rotation for security)
    $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE id = :id')
        ->execute([':id' => (int)$row['token_id']]);

    // Generate new token pair
    $tokens = generate_token_pair($pdo, $userId, $deviceName);

    return [
        'user' => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
        ],
        'tokens' => $tokens,
    ];
}

/**
 * Generate a new Bearer token for a user (LEGACY - use generate_token_pair instead).
 * @deprecated
 */
function generate_auth_token(PDO $pdo, int $userId, int $daysValid = 365): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$daysValid} days"));
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO auth_tokens (user_id, token, expires_at, created_at) VALUES (:user_id, :token, :expires_at, :created_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':token' => $token,
        ':expires_at' => $expiresAt,
        ':created_at' => $now,
    ]);

    return $token;
}

/**
 * Validate a Bearer token and return user data, or null if invalid (LEGACY).
 * @deprecated Use validate_access_token() instead.
 */
function validate_auth_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.role, a.expires_at
         FROM auth_tokens a
         JOIN users u ON u.id = a.user_id
         WHERE a.token = :token AND a.revoked = 0 AND a.expires_at > :now
         LIMIT 1'
    );
    $stmt->execute([':token' => $token, ':now' => date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    // Update last_used_at
    $pdo->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE token = :token')
        ->execute([':now' => date('Y-m-d H:i:s'), ':token' => $token]);

    return $user;
}

/**
 * Revoke a Bearer token.
 */
function revoke_auth_token(PDO $pdo, string $token): void
{
    $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token = :token')
        ->execute([':token' => $token]);
}

/**
 * Revoke all tokens for a specific user.
 */
function revoke_all_user_tokens(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = :user_id')
        ->execute([':user_id' => $userId]);
}

/**
 * Revoke a specific device session by token ID and user ID.
 */
function revoke_user_session(PDO $pdo, int $tokenId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE auth_tokens SET revoked = 1 WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([':id' => $tokenId, ':user_id' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * List all active sessions (device tokens) for a user.
 */
function list_user_sessions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, device_name, created_at, last_used_at, expires_at, refresh_expires_at
         FROM auth_tokens
         WHERE user_id = :user_id AND revoked = 0 AND refresh_expires_at > :now
         ORDER BY last_used_at DESC'
    );
    $stmt->execute([':user_id' => $userId, ':now' => date('Y-m-d H:i:s')]);
    return $stmt->fetchAll();
}

/**
 * Get authenticated user from either session or Bearer token.
 * Returns: ['id' => int, 'name' => string, 'email' => string, 'role' => string, 'auth_type' => 'session'|'bearer']
 */
function get_auth_user(): ?array
{
    // Try session first
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $userId = get_session_user_id();
    if ($userId > 0) {
        return [
            'id' => $userId,
            'name' => get_session_username(),
            'role' => get_session_role(),
            'auth_type' => 'session',
        ];
    }

    // Try Bearer token (access token)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        try {
            $pdo = get_db();
            $user = validate_access_token($pdo, $matches[1]);
            if ($user) {
                return [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'auth_type' => 'bearer',
                ];
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

/**
 * Require authentication from either session or Bearer token.
 */
function require_auth(): array
{
    $user = get_auth_user();
    if (!$user) {
        http_response_code(401);
        json_response(['error' => 'Authentication required'], 401);
    }
    return $user;
}

/**
 * Require specific roles (accepts both session and Bearer token).
 */
function require_role(array $allowedRoles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        json_response(['error' => 'Forbidden: insufficient permissions'], 403);
    }
    return $user;
}

// ============================================================
// SETUP MODE HELPERS
// ============================================================

/**
 * Check if the system has been set up (i.e., at least one admin/owner user exists).
 */
function is_setup_complete(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'owner')");
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if setup is complete (alias for is_setup_complete).
 */
function is_setup_mode_disabled(PDO $pdo): bool
{
    return is_setup_complete($pdo);
}

/**
 * Require that setup is complete. Redirects or exits with JSON if not.
 */
function require_setup_complete(): void
{
    try {
        $pdo = get_db();
        if (!is_setup_complete($pdo)) {
            $isApi = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
            if ($isApi) {
                http_response_code(503);
                json_response(['error' => 'System not set up. Please complete setup first.'], 503);
            } else {
                header('Location: /Setup/index.php');
                exit;
            }
        }
    } catch (Throwable $e) {
        header('Location: /Setup/index.php');
        exit;
    }
}

/**
 * Check if the current request is allowed in setup mode.
 * Returns true if setup is complete OR if the request targets setup endpoints.
 */
function check_setup_access(): bool
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/Setup/') !== false || strpos($requestUri, '/API/Setup/') !== false) {
        return true;
    }
    return false;
}

// ============================================================
// ORDER STATUS HISTORY LOGGING
// ============================================================

/**
 * Log an order status change to order_status_history table.
 */
function log_order_status_history(
    PDO $pdo,
    ?int $orderId,
    ?int $orderItemId,
    ?string $fromStatus,
    string $toStatus,
    ?int $changedByUserId = null,
    ?string $notes = null
): void {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO order_status_history (order_id, order_item_id, from_status, to_status, changed_by_user_id, notes, created_at)
         VALUES (:order_id, :order_item_id, :from_status, :to_status, :changed_by_user_id, :notes, :created_at)'
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':order_item_id' => $orderItemId,
        ':from_status' => $fromStatus,
        ':to_status' => $toStatus,
        ':changed_by_user_id' => $changedByUserId,
        ':notes' => $notes,
        ':created_at' => $now,
    ]);
}

// ============================================================
// NOTIFICATIONS
// ============================================================

/**
 * Create a notification in the queue.
 */
function create_notification(
    PDO $pdo,
    string $targetRole,
    ?int $targetUserId,
    string $title,
    string $body,
    string $type = 'order_update',
    ?string $referenceType = null,
    ?int $referenceId = null
): int {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (target_role, target_user_id, title, body, type, reference_type, reference_id, is_read, sent_to_push, created_at)
         VALUES (:target_role, :target_user_id, :title, :body, :type, :reference_type, :reference_id, 0, 0, :created_at)'
    );
    $stmt->execute([
        ':target_role' => $targetRole,
        ':target_user_id' => $targetUserId,
        ':title' => $title,
        ':body' => $body,
        ':type' => $type,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId,
        ':created_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetch pending notifications for a given role and/or user.
 */
function get_pending_notifications(PDO $pdo, ?string $role = null, ?int $userId = null): array
{
    $sql = 'SELECT * FROM notifications WHERE is_read = 0';
    $params = [];

    if ($role !== null && $role !== 'all') {
        $sql .= ' AND (target_role = :role OR target_role = \'all\')';
        $params[':role'] = $role;
    }
    if ($userId !== null) {
        $sql .= ' AND (target_user_id = :user_id OR target_user_id IS NULL)';
        $params[':user_id'] = $userId;
    }

    $sql .= ' ORDER BY created_at DESC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Mark a notification as read.
 */
function mark_notification_read(PDO $pdo, int $notificationId): void
{
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id')
        ->execute([':id' => $notificationId]);
}
