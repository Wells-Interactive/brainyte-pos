<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Print Job Manager
 *
 * Handles creation and tracking of print jobs.
 * Printing is an additional layer on top of the normal order workflow.
 * The database remains the source of truth — print jobs are created
 * alongside the order, not instead of the usual workflow.
 *
 * Workflow:
 *   Order Created
 *       ↓
 *   Order Item Saved to DB
 *       ↓
 *   Print Job Created (order_item_id, department, printer)
 *       ↓
 *   Printer attempts print
 *       ↓
 *   Print status recorded (completed/failed)
 */
class PrintJob
{
    private PDO $pdo;

    // Allowed printer types
    private const PRINTER_TYPES = ['default', 'kitchen', 'bar', 'thermal', 'a4', 'receipt'];

    // Print job statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_PRINTING = 'printing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Max print attempts before failing permanently
    private const MAX_ATTEMPTS = 3;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a print job for an order item.
     *
     * @param int $orderItemId
     * @param int $orderId
     * @param string $department 'kitchen' or 'bar'
     * @param string $printer
     * @return int Print job ID
     */
    public function create(int $orderItemId, int $orderId, string $department, string $printer = 'default'): int
    {
        $now = date('Y-m-d H:i:s');
        $printer = in_array($printer, self::PRINTER_TYPES, true) ? $printer : 'default';

        $stmt = $this->pdo->prepare(
            'INSERT INTO print_jobs (order_item_id, order_id, department, printer, status, attempts, created_at)
             VALUES (:order_item_id, :order_id, :department, :printer, :status, 0, :created_at)'
        );
        $stmt->execute([
            ':order_item_id' => $orderItemId,
            ':order_id' => $orderId,
            ':department' => $department,
            ':printer' => $printer,
            ':status' => self::STATUS_PENDING,
            ':created_at' => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Create print jobs for all items in an order, grouped by department.
     *
     * @param int $orderId
     * @param array $items Grouped items array from order creation
     * @return array{kitchen: int[], bar: int[]} Created print job IDs
     */
    public function createFromOrder(int $orderId, array $kitchenItems, array $barItems): array
    {
        $result = [
            'kitchen' => [],
            'bar' => [],
        ];

        try {
            $this->pdo->beginTransaction();

            foreach ($kitchenItems as $item) {
                $itemId = (int)($item['id'] ?? 0);
                if ($itemId > 0) {
                    $jobId = $this->create($itemId, $orderId, 'kitchen', 'kitchen');
                    $result['kitchen'][] = $jobId;
                }
            }

            foreach ($barItems as $item) {
                $itemId = (int)($item['id'] ?? 0);
                if ($itemId > 0) {
                    $jobId = $this->create($itemId, $orderId, 'bar', 'bar');
                    $result['bar'][] = $jobId;
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Log but don't fail — print jobs are auxiliary
            error_log('PrintJob creation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Mark a print job as completed.
     */
    public function markCompleted(int $jobId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE print_jobs SET status = :status, printed_at = :printed_at WHERE id = :id AND status != :cancelled'
        );
        $stmt->execute([
            ':status' => self::STATUS_COMPLETED,
            ':printed_at' => $now,
            ':id' => $jobId,
            ':cancelled' => self::STATUS_CANCELLED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a print job as failed with error details.
     * Retries automatically up to MAX_ATTEMPTS.
     */
    public function markFailed(int $jobId, string $error): bool
    {
        $now = date('Y-m-d H:i:s');

        // Get current attempts
        $stmt = $this->pdo->prepare('SELECT attempts FROM print_jobs WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            return false;
        }

        $attempts = (int)$job['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Mark as permanently failed
            $stmt = $this->pdo->prepare(
                'UPDATE print_jobs SET status = :status, attempts = :attempts, last_error = :last_error WHERE id = :id'
            );
            $stmt->execute([
                ':status' => self::STATUS_FAILED,
                ':attempts' => $attempts,
                ':last_error' => $error,
                ':id' => $jobId,
            ]);
        } else {
            // Retry: set back to pending so it can be retried
            $stmt = $this->pdo->prepare(
                'UPDATE print_jobs SET status = :status, attempts = :attempts, last_error = :last_error WHERE id = :id'
            );
            $stmt->execute([
                ':status' => self::STATUS_PENDING,
                ':attempts' => $attempts,
                ':last_error' => $error,
                ':id' => $jobId,
            ]);
        }

        return true;
    }

    /**
     * Mark a print job as currently printing.
     */
    public function markPrinting(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE print_jobs SET status = :status WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status' => self::STATUS_PRINTING,
            ':id' => $jobId,
            ':pending' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get pending print jobs for a department.
     */
    public function getPendingJobs(string $department): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pj.*, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price,
                    o.table_id, o.special_instructions AS order_instructions,
                    u.name AS waiter_name
             FROM print_jobs pj
             JOIN order_items oi ON oi.id = pj.order_item_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = pj.order_id
             JOIN users u ON u.id = o.waiter_id
             WHERE pj.department = :department AND pj.status = :status
             ORDER BY pj.created_at ASC
             LIMIT 20'
        );
        $stmt->execute([
            ':department' => $department,
            ':status' => self::STATUS_PENDING,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get failed print jobs that need attention.
     */
    public function getFailedJobs(): array
    {
        $stmt = $this->pdo->query(
            'SELECT pj.*, oi.menu_item_id, mi.name AS item_name, oi.quantity,
                    o.table_id, u.name AS waiter_name
             FROM print_jobs pj
             JOIN order_items oi ON oi.id = pj.order_item_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = pj.order_id
             JOIN users u ON u.id = o.waiter_id
             WHERE pj.status = :status
             ORDER BY pj.created_at DESC
             LIMIT 50'
        );
        $stmt->execute([':status' => self::STATUS_FAILED]);
        return $stmt->fetchAll();
    }

    /**
     * Retry a failed print job.
     */
    public function retry(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE print_jobs SET status = :status, attempts = 0, last_error = NULL WHERE id = :id AND status = :failed'
        );
        $stmt->execute([
            ':status' => self::STATUS_PENDING,
            ':id' => $jobId,
            ':failed' => self::STATUS_FAILED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel a print job.
     */
    public function cancel(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE print_jobs SET status = :status WHERE id = :id AND status IN (:pending, :printing, :failed)'
        );
        $stmt->execute([
            ':status' => self::STATUS_CANCELLED,
            ':id' => $jobId,
            ':pending' => self::STATUS_PENDING,
            ':printing' => self::STATUS_PRINTING,
            ':failed' => self::STATUS_FAILED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the current status of a print job.
     */
    public function getStatus(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pj.*, mi.name AS item_name, oi.quantity, o.table_id
             FROM print_jobs pj
             JOIN order_items oi ON oi.id = pj.order_item_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = pj.order_id
             WHERE pj.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();

        if ($job) {
            $job['id'] = (int)$job['id'];
            $job['order_item_id'] = (int)$job['order_item_id'];
            $job['order_id'] = (int)$job['order_id'];
            $job['attempts'] = (int)$job['attempts'];
        }

        return $job ?: null;
    }

    /**
     * Check if direct_printing is enabled.
     */
    public function isDirectPrintingEnabled(): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'direct_printing' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            return $row && $row['setting_value'] === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get print statistics.
     */
    public function getStatistics(): array
    {
        $stats = [];

        // Total jobs
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM print_jobs");
        $stats['total'] = (int)$stmt->fetchColumn();

        // By status
        foreach ([self::STATUS_PENDING, self::STATUS_PRINTING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED] as $status) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM print_jobs WHERE status = :status");
            $stmt->execute([':status' => $status]);
            $stats[$status] = (int)$stmt->fetchColumn();
        }

        // Today's jobs
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM print_jobs WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = (int)$stmt->fetchColumn();

        return $stats;
    }
}

