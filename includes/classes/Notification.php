<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Notification Management
 * 
 * Handles creation and retrieval of notifications for all user roles.
 * Supports targeting specific roles or individual users.
 */
class Notification
{
    private PDO $pdo;

    public const ALLOWED_ROLES = ['waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'all'];
    public const ALLOWED_TYPES = ['order_update', 'status_change', 'payment', 'system', 'alert'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new notification.
     *
     * @return int The notification ID
     */
    public function create(
        string $targetRole,
        ?int $targetUserId,
        string $title,
        string $body,
        string $type = 'order_update',
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        if (!in_array($targetRole, self::ALLOWED_ROLES, true)) {
            $targetRole = 'all';
        }

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'order_update';
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
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

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get pending notifications for a given role and/or user.
     */
    public function getPending(?string $role = null, ?int $userId = null): array
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(int $notificationId): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id' => $notificationId]);
    }

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllRead(string $role, ?int $userId = null): int
    {
        $sql = 'UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND (target_role = :role OR target_role = \'all\')';
        $params = [':role' => $role];

        if ($userId !== null) {
            $sql .= ' AND (target_user_id = :user_id OR target_user_id IS NULL)';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Delete old notifications (cleanup).
     */
    public function deleteOlderThan(int $days = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->pdo->prepare('DELETE FROM notifications WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Get notification count for a user.
     */
    public function getUnreadCount(string $role, ?int $userId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (target_role = :role OR target_role = \'all\')';
        $params = [':role' => $role];

        if ($userId !== null) {
            $sql .= ' AND (target_user_id = :user_id OR target_user_id IS NULL)';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
