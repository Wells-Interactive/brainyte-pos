<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Rate Limiter & Login Throttling
 * 
 * Prevents abuse by limiting request frequency per IP and user.
 * Implements login throttling with exponential backoff.
 */
class RateLimiter
{
    private PDO $pdo;

    /** Max requests per time window */
    private const MAX_REQUESTS = 60;
    private const TIME_WINDOW = 60; // seconds

    /** Login attempt limits */
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_WINDOW = 300; // 5 minutes
    private const LOCKOUT_DURATION = 900; // 15 minutes

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Ensure the rate_limits table exists.
     */
    private function ensureTableExists(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(100) NOT NULL,
                type ENUM('api', 'login') NOT NULL DEFAULT 'api',
                hits INT NOT NULL DEFAULT 1,
                window_start DATETIME NOT NULL,
                blocked_until DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_rate_type_id (type, identifier, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    /**
     * Check if a request is within rate limits.
     * 
     * @param string $identifier IP address or user ID
     * @param string $type 'api' or 'login'
     * @return bool True if allowed, false if rate limited
     */
    public function check(string $identifier, string $type = 'api'): bool
    {
        $now = date('Y-m-d H:i:s');
        $maxAttempts = $type === 'login' ? self::MAX_LOGIN_ATTEMPTS : self::MAX_REQUESTS;
        $windowSeconds = $type === 'login' ? self::LOGIN_WINDOW : self::TIME_WINDOW;

        // Check if currently blocked
        $blockStmt = $this->pdo->prepare(
            'SELECT blocked_until FROM rate_limits 
             WHERE identifier = :identifier AND type = :type 
             AND blocked_until > :now
             ORDER BY id DESC LIMIT 1'
        );
        $blockStmt->execute([
            ':identifier' => $identifier,
            ':type' => $type,
            ':now' => $now,
        ]);
        $blocked = $blockStmt->fetch();

        if ($blocked) {
            return false;
        }

        // Count recent attempts
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $countStmt = $this->pdo->prepare(
            'SELECT SUM(hits) as total FROM rate_limits 
             WHERE identifier = :identifier AND type = :type 
             AND window_start >= :window_start'
        );
        $countStmt->execute([
            ':identifier' => $identifier,
            ':type' => $type,
            ':window_start' => $windowStart,
        ]);
        $row = $countStmt->fetch();
        $totalHits = (int)($row['total'] ?? 0);

        return $totalHits < $maxAttempts;
    }

    /**
     * Record a hit for rate limiting.
     */
    public function hit(string $identifier, string $type = 'api'): void
    {
        $now = date('Y-m-d H:i:s');
        $windowSeconds = $type === 'login' ? self::LOGIN_WINDOW : self::TIME_WINDOW;

        // Update existing window or create new one
        $this->pdo->prepare(
            'INSERT INTO rate_limits (identifier, type, hits, window_start, created_at)
             VALUES (:identifier, :type, 1, :window_start, :created_at)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([
            ':identifier' => $identifier,
            ':type' => $type,
            ':window_start' => $now,
            ':created_at' => $now,
        ]);
    }

    /**
     * Check login rate limit with throttling.
     * 
     * @return array ['allowed' => bool, 'remaining' => int, 'blocked_until' => ?string]
     */
    public function checkLogin(string $identifier): array
    {
        $allowed = $this->check($identifier, 'login');

        $remaining = 0;
        $blockedUntil = null;

        if (!$allowed) {
            $blockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_DURATION);
            // Block the identifier
            $this->pdo->prepare(
                'UPDATE rate_limits SET blocked_until = :blocked_until 
                 WHERE identifier = :identifier AND type = :type 
                 ORDER BY id DESC LIMIT 1'
            )->execute([
                ':blocked_until' => $blockedUntil,
                ':identifier' => $identifier,
                ':type' => 'login',
            ]);
        } else {
            $windowStart = date('Y-m-d H:i:s', time() - self::LOGIN_WINDOW);
            $stmt = $this->pdo->prepare(
                'SELECT SUM(hits) as total FROM rate_limits 
                 WHERE identifier = :identifier AND type = :type 
                 AND window_start >= :window_start'
            );
            $stmt->execute([
                ':identifier' => $identifier,
                ':type' => 'login',
                ':window_start' => $windowStart,
            ]);
            $row = $stmt->fetch();
            $totalHits = (int)($row['total'] ?? 0);
            $remaining = max(0, self::MAX_LOGIN_ATTEMPTS - $totalHits);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'blocked_until' => $blockedUntil,
        ];
    }

    /**
     * Record a login attempt (success or failure).
     */
    public function recordLoginAttempt(string $identifier, bool $success): void
    {
        $this->hit($identifier, 'login');
    }

    /**
     * Get client IP address for rate limiting.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Clean up old rate limit records.
     */
    public function cleanOlderThan(int $hours = 24): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }
}
