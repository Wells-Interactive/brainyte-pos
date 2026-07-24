<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Authentication Handler
 * 
 * Manages both session-based auth (web) and Bearer token auth (Flutter).
 * Provides user authentication, authorization checks, and token management.
 * 
 * Token Security:
 * - Access tokens: short-lived (15-60 minutes)
 * - Refresh tokens: long-lived (30-90 days), revocable per device
 * - Device/session tracking for explicit revocation
 */
class Auth
{
    private PDO $pdo;
    private ?array $user = null;
    private string $authType = '';

    // Access token lifetime in minutes
    private const ACCESS_TOKEN_MINUTES = 30;
    // Refresh token lifetime in days
    private const REFRESH_TOKEN_DAYS = 60;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Attempt to authenticate a user with email and password.
     * Returns user array or null on failure.
     */
    public function login(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (empty($user) || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        return [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    /**
     * Create a session for an authenticated user.
     */
    public function createSession(array $user, bool $regenerate = true): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($regenerate) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        // Generate CSRF token
        CSRF::generate();
    }

    /**
     * Destroy the current session.
     */
    public function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Generate a token pair (access + refresh) for API access (Flutter).
     *
     * @param int    $userId      User ID to associate tokens with
     * @param string $deviceName  Optional device identifier for session tracking
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int}
     */
    public function generateTokenPair(int $userId, string $deviceName = ''): array
    {
        $now = date('Y-m-d H:i:s');
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $accessExpiresAt = date('Y-m-d H:i:s', strtotime('+' . self::ACCESS_TOKEN_MINUTES . ' minutes'));
        $refreshExpiresAt = date('Y-m-d H:i:s', strtotime('+' . self::REFRESH_TOKEN_DAYS . ' days'));

        // Ensure auth_tokens has device_name column
        $this->ensureTokenColumns();

        $stmt = $this->pdo->prepare(
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
            'expires_in' => self::ACCESS_TOKEN_MINUTES * 60,
            'refresh_expires_in' => self::REFRESH_TOKEN_DAYS * 86400,
        ];
    }

    /**
     * Ensure auth_tokens has the required columns for token pair support.
     */
    private function ensureTokenColumns(): void
    {
        $this->ensureColumn('auth_tokens', 'refresh_token', 'VARCHAR(64) DEFAULT NULL AFTER `token`');
        $this->ensureColumn('auth_tokens', 'device_name', 'VARCHAR(255) DEFAULT NULL');
        $this->ensureColumn('auth_tokens', 'refresh_expires_at', 'DATETIME DEFAULT NULL');
    }

    /**
     * Add a column to a table if it doesn't exist.
     */
    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->query(sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $this->pdo->quote($column)));
            if ($stmt->fetch()) {
                return;
            }
            $this->pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        } catch (Throwable $e) {
            // Ignore errors (table may not exist yet)
        }
    }

    /**
     * Validate an access token and return user data.
     */
    public function validateAccessToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role, a.id AS token_id, a.expires_at, a.refresh_expires_at
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
        $this->pdo->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE token = :token')
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
     * Returns null if invalid/expired/revoked.
     *
     * @return array{user: array, tokens: array}|null
     */
    public function validateRefreshToken(string $refreshToken, string $deviceName = ''): ?array
    {
        $stmt = $this->pdo->prepare(
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
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE id = :id')
            ->execute([':id' => (int)$row['token_id']]);

        // Generate new token pair
        $tokens = $this->generateTokenPair($userId, $deviceName);

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
     * Generate a Bearer token for API access (Flutter) - LEGACY.
     * 
     * @deprecated Use generateTokenPair() instead
     */
    public function generateToken(int $userId, int $daysValid = 365): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$daysValid} days"));
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token, expires_at, created_at) 
             VALUES (:user_id, :token, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $token,
            ':expires_at' => $expiresAt,
            ':created_at' => $now,
        ]);

        return $token;
    }

    /**
     * Validate a Bearer token and return user data - LEGACY.
     * 
     * @deprecated Use validateAccessToken() instead
     */
    public function validateToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
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
        $this->pdo->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE token = :token')
            ->execute([':now' => date('Y-m-d H:i:s'), ':token' => $token]);

        return [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    /**
     * Revoke a specific Bearer token.
     */
    public function revokeToken(string $token): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token = :token')
            ->execute([':token' => $token]);
    }

    /**
     * Revoke all tokens for a specific user (e.g., password reset, account lock).
     */
    public function revokeAllUserTokens(int $userId): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE user_id = :user_id')
            ->execute([':user_id' => $userId]);
    }

    /**
     * Revoke a specific token by its database ID.
     */
    public function revokeTokenById(int $tokenId): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE id = :id')
            ->execute([':id' => $tokenId]);
    }

    /**
     * List all active sessions (tokens) for a user for device management.
     */
    public function listUserSessions(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, device_name, created_at, last_used_at, expires_at, refresh_expires_at
             FROM auth_tokens
             WHERE user_id = :user_id AND revoked = 0 AND refresh_expires_at > :now
             ORDER BY last_used_at DESC'
        );
        $stmt->execute([':user_id' => $userId, ':now' => date('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    /**
     * Revoke a specific device session by token ID and user ID.
     */
    public function revokeSession(int $tokenId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET revoked = 1 WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $tokenId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get currently authenticated user from session or Bearer token.
     */
    public function getCurrentUser(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        // Try session first
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
        if ($userId > 0) {
            $this->user = [
                'id' => $userId,
                'name' => $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '',
                'role' => $_SESSION['role'] ?? $_SESSION['user']['role'] ?? '',
                'auth_type' => 'session',
            ];
            $this->authType = 'session';
            return $this->user;
        }

        // Try Bearer token (access token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? '';
        
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $tokenUser = $this->validateAccessToken($matches[1]);
            if ($tokenUser) {
                $tokenUser['auth_type'] = 'bearer';
                $this->user = $tokenUser;
                $this->authType = 'bearer';
                return $this->user;
            }
        }

        return null;
    }

    /**
     * Require authentication. Returns user or sends 401.
     */
    public function requireAuth(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'Authentication required',
                'meta' => null,
            ]);
            exit;
        }
        return $user;
    }

    /**
     * Require specific roles. Returns user or sends 403.
     */
    public function requireRole(array $allowedRoles): array
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'], $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'Forbidden: insufficient permissions',
                'meta' => null,
            ]);
            exit;
        }
        return $user;
    }

    /**
     * Check if current user has a specific role.
     */
    public function hasRole(array $allowedRoles): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        return in_array($user['role'], $allowedRoles, true);
    }

    /**
     * Get the auth type ('session' or 'bearer').
     */
    public function getAuthType(): string
    {
        return $this->authType;
    }

    /**
     * Redirect user to their appropriate dashboard based on role.
     */
    public function redirectToDashboard(): void
    {
        $role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
        
        switch ($role) {
            case 'waiter':
                header('Location: /Waiter/index.php');
                break;
            case 'kitchen':
                header('Location: /Kitchen/index.php');
                break;
            case 'bar':
                header('Location: /Bar/index.php');
                break;
            case 'manager':
            case 'supervisor':
                header('Location: /Manager/index.php');
                break;
            case 'admin':
            case 'owner':
                header('Location: /index.php');
                break;
            default:
                header('Location: /Login/index.php');
                break;
        }
        exit;
    }
}
