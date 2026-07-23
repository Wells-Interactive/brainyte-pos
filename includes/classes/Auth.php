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
 */
class Auth
{
    private PDO $pdo;
    private ?array $user = null;
    private string $authType = '';

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
     * Generate a Bearer token for API access (Flutter).
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
     * Validate a Bearer token and return user data.
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
     * Revoke a Bearer token.
     */
    public function revokeToken(string $token): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token = :token')
            ->execute([':token' => $token]);
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

        // Try Bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? '';
        
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $tokenUser = $this->validateToken($matches[1]);
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
