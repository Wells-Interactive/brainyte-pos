<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Audit Logging
 * 
 * Records all security-relevant events for audit trails.
 * Tracks user actions, timestamps, IP addresses, and user agents.
 */
class AuditLog
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Ensure the audit_logs table exists.
     */
    private function ensureTableExists(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                action VARCHAR(100) NOT NULL,
                resource_type VARCHAR(50) DEFAULT NULL,
                resource_id INT DEFAULT NULL,
                details TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_audit_user (user_id),
                INDEX idx_audit_action (action),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    /**
     * Log an event to the audit trail.
     */
    public function log(
        ?int $userId,
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $details = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (:user_id, :action, :resource_type, :resource_id, :details, :ip_address, :user_agent, :created_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':details' => $details,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ':user_agent' => ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Log a login event.
     */
    public function login(int $userId, bool $success, ?string $reason = null): int
    {
        return $this->log(
            $success ? $userId : null,
            $success ? 'login_success' : 'login_failed',
            'auth',
            $userId,
            $reason ?? ($success ? 'User logged in successfully' : 'Failed login attempt')
        );
    }

    /**
     * Log a logout event.
     */
    public function logout(int $userId): int
    {
        return $this->log($userId, 'logout', 'auth', $userId, 'User logged out');
    }

    /**
     * Log a data change event.
     */
    public function dataChange(int $userId, string $resourceType, int $resourceId, string $action, ?string $details = null): int
    {
        return $this->log($userId, "data_{$action}", $resourceType, $resourceId, $details);
    }

    /**
     * Log a permission denied event.
     */
    public function permissionDenied(int $userId, string $resourceType, ?int $resourceId = null): int
    {
        return $this->log($userId, 'permission_denied', $resourceType, $resourceId, 'Attempted access without sufficient permissions');
    }

    /**
     * Log an API request (for rate limiting monitoring).
     */
    public function apiRequest(int $userId, string $endpoint, int $statusCode): int
    {
        return $this->log($userId, 'api_request', 'api', null, "{$endpoint} -> {$statusCode}");
    }

    /**
     * Get recent audit logs.
     */
    public function getRecent(int $limit = 50, ?int $userId = null): array
    {
        $sql = 'SELECT al.*, u.name AS user_name
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE 1 = 1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND al.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sql .= ' ORDER BY al.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Clean up old audit logs.
     */
    public function cleanOlderThan(int $days = 90): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->pdo->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }
}
