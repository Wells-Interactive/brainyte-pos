<?php
declare(strict_types=1);

namespace App;

use PDO;
use InvalidArgumentException;

/**
 * Order Management
 * 
 * Handles order creation, status management, and retrieval.
 * Uses prepared statements throughout and integrates with
 * history logging and notifications.
 */
class Order
{
    private PDO $pdo;

    public const ALLOWED_STATUSES = ['pending', 'preparing', 'ready', 'served', 'completed'];
    public const ALLOWED_PAYMENT_METHODS = ['cash', 'pos', 'transfer', 'pending'];
    public const FOOD_CATEGORIES = ['rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new order with items.
     * 
     * @param array $data { table_id, waiter_id, items, instructions?, payment_method? }
     * @return array { order_id, items, direct_print? }
     */
    public function create(array $data): array
    {
        $tableId = (int)($data['table_id'] ?? 0);
        $waiterId = (int)($data['waiter_id'] ?? 0);
        $items = $data['items'] ?? [];
        $instructions = isset($data['instructions']) ? trim((string)$data['instructions']) : null;
        $paymentMethod = in_array(
            strtolower((string)($data['payment_method'] ?? 'pending')),
            self::ALLOWED_PAYMENT_METHODS,
            true
        ) ? strtolower((string)($data['payment_method'] ?? 'pending')) : 'pending';

        if ($tableId <= 0 || empty($items) || $waiterId <= 0) {
            throw new InvalidArgumentException('Table, waiter, and order items are required');
        }

        $now = date('Y-m-d H:i:s');
        $menuItemService = new MenuItem($this->pdo);

        // Ensure table exists
        $tableStmt = $this->pdo->prepare('SELECT id, status FROM restaurant_tables WHERE id = :id LIMIT 1');
        $tableStmt->execute([':id' => $tableId]);
        $table = $tableStmt->fetch();

        if (!$table) {
            $this->pdo->prepare(
                'INSERT INTO restaurant_tables (id, name, status, created_at) VALUES (:id, :name, :status, :created_at)'
            )->execute([
                ':id' => $tableId,
                ':name' => "Table {$tableId}",
                ':status' => 'available',
                ':created_at' => $now,
            ]);
        }

        $this->pdo->beginTransaction();

        try {
            // Create the order
            $orderStmt = $this->pdo->prepare(
                'INSERT INTO orders (table_id, waiter_id, status, special_instructions, payment_method, created_at, updated_at)
                 VALUES (:table_id, :waiter_id, :status, :instructions, :payment_method, :created_at, :updated_at)'
            );
            $orderStmt->execute([
                ':table_id' => $tableId,
                ':waiter_id' => $waiterId,
                ':status' => 'pending',
                ':instructions' => $instructions,
                ':payment_method' => $paymentMethod,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            // Log order creation
            $this->logHistory($orderId, null, null, 'pending', $waiterId, 'Order created');

            // Process items
            $insertItemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, status, routed_to, created_at)
                 VALUES (:order_id, :menu_item_id, :quantity, :unit_price, :status, :routed_to, :created_at)'
            );

            $notificationService = new Notification($this->pdo);

            foreach ($items as $item) {
                $menuItemId = (int)($item['menu_item_id'] ?? 0);
                $quantity = max(1, (int)($item['quantity'] ?? 1));

                if ($menuItemId <= 0) {
                    continue;
                }

                $product = $menuItemService->getById($menuItemId);

                // Try to create fallback if missing
                if (!$product) {
                    $product = $menuItemService->ensureFallbackItem($menuItemId);
                }

                if (!$product) {
                    continue;
                }

                $routedTo = MenuItem::getRouting($product['category']);

                $insertItemStmt->execute([
                    ':order_id' => $orderId,
                    ':menu_item_id' => $menuItemId,
                    ':quantity' => $quantity,
                    ':unit_price' => $product['price'],
                    ':status' => 'pending',
                    ':routed_to' => $routedTo,
                    ':created_at' => $now,
                ]);

                $orderItemId = (int)$this->pdo->lastInsertId();

                // Log item creation
                $this->logHistory($orderId, $orderItemId, null, 'pending', $waiterId, "Item added (routed to {$routedTo})");

                // Notify kitchen/bar
                $notificationService->create(
                    $routedTo,
                    null,
                    "New {$routedTo} Order",
                    "Table {$tableId}: {$product['name']} x{$quantity}",
                    'order_update',
                    'order_item',
                    $orderItemId
                );
            }

            // Occupy the table
            $this->pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'occupied', ':id' => $tableId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Check direct printing setting
        $settings = new Settings($this->pdo);
        $directPrinting = $settings->getBool('direct_printing');

        $result = [
            'order_id' => $orderId,
            'created_at' => $now,
            'table_id' => $tableId,
            'instructions' => $instructions,
        ];

        if ($directPrinting) {
            $result['direct_print'] = true;
            $result['kitchen_items'] = $this->getPrintableItems($orderId, 'kitchen', $waiterId);
            $result['bar_items'] = $this->getPrintableItems($orderId, 'bar', $waiterId);
        }

        return $result;
    }

    /**
     * Get items for direct printing.
     */
    private function getPrintableItems(int $orderId, string $routedTo, int $waiterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.routed_to, o.table_id, oi.status
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = :order_id AND oi.routed_to = :routed_to
             ORDER BY oi.id'
        );
        $stmt->execute([':order_id' => $orderId, ':routed_to' => $routedTo]);
        $items = $stmt->fetchAll();

        $waiterName = '';
        $userStmt = $this->pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute([':id' => $waiterId]);
        $waiter = $userStmt->fetch();
        if ($waiter) {
            $waiterName = $waiter['name'];
        }

        return array_map(function ($item) use ($waiterName) {
            return [
                'id' => (int)$item['id'],
                'menu_item_id' => (int)$item['menu_item_id'],
                'item_name' => $item['item_name'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'table_id' => $item['table_id'],
                'waiter_name' => $waiterName,
            ];
        }, $items);
    }

    /**
     * Get orders with optional filters.
     */
    public function getOrders(array $filters = []): array
    {
        $status = $filters['status'] ?? '';
        $role = $filters['role'] ?? '';
        $limit = isset($filters['limit']) ? min(200, max(1, (int)$filters['limit'])) : 100;
        $orderId = isset($filters['order_id']) ? (int)$filters['order_id'] : 0;

        $sql = 'SELECT o.id, o.table_id, o.waiter_id, o.status, o.special_instructions AS instructions,
                       o.payment_method, o.created_at, o.updated_at,
                       u.name AS waiter_name
                FROM orders o
                JOIN users u ON u.id = o.waiter_id
                WHERE 1 = 1';
        $params = [];

        if (in_array($status, self::ALLOWED_STATUSES, true)) {
            $sql .= ' AND o.status = :status';
            $params[':status'] = $status;
        }

        if ($orderId > 0) {
            $sql .= ' AND o.id = :order_id';
            $params[':order_id'] = $orderId;
        }

        $sql .= ' ORDER BY o.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$order) {
            $orderIdVal = (int)$order['id'];

            $itemSql = 'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, oi.created_at
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.menu_item_id
                        WHERE oi.order_id = :order_id';

            $itemParams = [':order_id' => $orderIdVal];

            if ($role === 'kitchen' || $role === 'bar') {
                $itemSql .= ' AND oi.routed_to = :role';
                $itemParams[':role'] = $role;
            }

            $itemSql .= ' ORDER BY oi.id';
            $itemStmt = $this->pdo->prepare($itemSql);
            $itemStmt->execute($itemParams);
            $order['items'] = $itemStmt->fetchAll();

            $order['id'] = $orderIdVal;
            $order['table_id'] = (int)$order['table_id'];
            $order['waiter_id'] = (int)$order['waiter_id'];
        }

        return $orders;
    }

    /**
     * Update order item status.
     */
    public function updateItemStatus(int $itemId, string $newStatus, int $changedByUserId): array
    {
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status: {$newStatus}");
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT oi.order_id, oi.status, mi.name AS item_name, oi.routed_to, o.table_id
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 JOIN menu_items mi ON mi.id = oi.menu_item_id
                 WHERE oi.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $itemId]);
            $orderItem = $stmt->fetch();

            if (!$orderItem) {
                throw new InvalidArgumentException('Order item not found');
            }

            $oldStatus = $orderItem['status'];
            $orderId = (int)$orderItem['order_id'];

            $this->pdo->prepare('UPDATE order_items SET status = :status WHERE id = :id')
                ->execute([':status' => $newStatus, ':id' => $itemId]);

            $this->logHistory($orderId, $itemId, $oldStatus, $newStatus, $changedByUserId);

            // Notify waiter when item is ready
            if ($newStatus === 'ready') {
                $notificationService = new Notification($this->pdo);
                $notificationService->create(
                    'waiter',
                    null,
                    "Item Ready for Table {$orderItem['table_id']}",
                    "{$orderItem['item_name']} is ready to serve",
                    'status_change',
                    'order_item',
                    $itemId
                );
            }

            // Check for order-level status updates
            $this->syncOrderStatus($orderId, $newStatus, $changedByUserId);

            $this->pdo->commit();

            return [
                'item_id' => $itemId,
                'order_id' => $orderId,
                'status' => $newStatus,
                'old_status' => $oldStatus,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update entire order status.
     */
    public function updateOrderStatus(int $orderId, string $newStatus, int $changedByUserId): array
    {
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status: {$newStatus}");
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new InvalidArgumentException('Order not found');
            }

            $oldStatus = $order['status'];
            $now = date('Y-m-d H:i:s');

            $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                ->execute([':status' => $newStatus, ':updated_at' => $now, ':order_id' => $orderId]);

            // Also update all items
            $this->pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
                ->execute([':status' => $newStatus, ':order_id' => $orderId]);

            $this->logHistory($orderId, null, $oldStatus, $newStatus, $changedByUserId, 'Bulk order status update');

            // If completed, free the table
            if ($newStatus === 'completed') {
                $this->freeTable($orderId);
            }

            $this->pdo->commit();

            return [
                'order_id' => $orderId,
                'status' => $newStatus,
                'old_status' => $oldStatus,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark an order as paid (completes order and frees table).
     */
    public function markPaid(int $orderId, string $paymentMethod, int $changedByUserId): array
    {
        if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException("Invalid payment method: {$paymentMethod}");
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT status, table_id FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new InvalidArgumentException('Order not found');
            }

            $oldStatus = $order['status'];
            $now = date('Y-m-d H:i:s');

            $this->pdo->prepare(
                'UPDATE orders SET status = :status, payment_method = :payment_method, updated_at = :updated_at WHERE id = :order_id'
            )->execute([
                ':status' => 'completed',
                ':payment_method' => $paymentMethod,
                ':updated_at' => $now,
                ':order_id' => $orderId,
            ]);

            $this->pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
                ->execute([':status' => 'completed', ':order_id' => $orderId]);

            $this->logHistory($orderId, null, $oldStatus, 'completed', $changedByUserId, "Payment: {$paymentMethod}");

            // Free the table
            $this->pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $order['table_id']]);

            // Notify waiter
            $notificationService = new Notification($this->pdo);
            $notificationService->create(
                'waiter',
                null,
                "Table {$order['table_id']} Paid",
                "Order #{$orderId} for Table {$order['table_id']} completed via {$paymentMethod}",
                'payment',
                'order',
                $orderId
            );

            $this->pdo->commit();

            return [
                'order_id' => $orderId,
                'table_id' => (int)$order['table_id'],
                'status' => 'completed',
                'payment_method' => $paymentMethod,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel an order.
     */
    public function cancel(int $orderId, int $changedByUserId): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT status, table_id FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new InvalidArgumentException('Order not found');
            }

            $oldStatus = $order['status'];
            $now = date('Y-m-d H:i:s');

            $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                ->execute([':status' => 'completed', ':updated_at' => $now, ':order_id' => $orderId]);

            $this->pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
                ->execute([':status' => 'completed', ':order_id' => $orderId]);

            $this->logHistory($orderId, null, $oldStatus, 'completed', $changedByUserId, 'Order cancelled');

            $this->freeTable($orderId);

            $this->pdo->commit();

            return [
                'order_id' => $orderId,
                'table_id' => (int)$order['table_id'],
                'status' => 'completed',
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sync order status based on item statuses.
     */
    private function syncOrderStatus(int $orderId, string $itemStatus, int $changedByUserId): void
    {
        $now = date('Y-m-d H:i:s');

        if ($itemStatus === 'ready') {
            $checkStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != 'ready'"
            );
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'ready', ':updated_at' => $now, ':order_id' => $orderId]);
                $this->logHistory($orderId, null, 'preparing', 'ready', $changedByUserId, 'All items ready');

                $notificationService = new Notification($this->pdo);
                $notificationService->create('waiter', null, "Order #{$orderId} Ready", "Order #{$orderId} is ready to serve", 'status_change', 'order', $orderId);
            }
        } elseif ($itemStatus === 'served') {
            $checkStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status NOT IN ('served', 'completed')"
            );
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'served', ':updated_at' => $now, ':order_id' => $orderId]);
                $this->logHistory($orderId, null, 'ready', 'served', $changedByUserId, 'All items served');
            }
        } elseif ($itemStatus === 'completed') {
            $checkStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != 'completed'"
            );
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'completed', ':updated_at' => $now, ':order_id' => $orderId]);
                $this->logHistory($orderId, null, 'served', 'completed', $changedByUserId);
                $this->freeTable($orderId);
            }
        }
    }

    /**
     * Free the table associated with an order.
     */
    private function freeTable(int $orderId): void
    {
        $tableStmt = $this->pdo->prepare('SELECT table_id FROM orders WHERE id = :id');
        $tableStmt->execute([':id' => $orderId]);
        $tableRow = $tableStmt->fetch();
        if ($tableRow) {
            $this->pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $tableRow['table_id']]);
        }
    }

    /**
     * Log order status history.
     */
    private function logHistory(
        int $orderId,
        ?int $orderItemId,
        ?string $fromStatus,
        string $toStatus,
        ?int $changedByUserId,
        ?string $notes = null
    ): void {
        $stmt = $this->pdo->prepare(
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
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get order status history.
     */
    public function getHistory(int $orderId, ?int $orderItemId = null, int $limit = 50): array
    {
        $sql = 'SELECT h.*, u.name AS changed_by_name
                FROM order_status_history h
                LEFT JOIN users u ON u.id = h.changed_by_user_id
                WHERE h.order_id = :order_id';
        $params = [':order_id' => $orderId];

        if ($orderItemId !== null) {
            $sql .= ' AND h.order_item_id = :order_item_id';
            $params[':order_item_id'] = $orderItemId;
        }

        $sql .= ' ORDER BY h.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Mark table as paid (completes the latest active order for a table).
     */
    public function markTablePaid(int $tableId, string $paymentMethod, int $changedByUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM orders WHERE table_id = :table_id AND status != :completed ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':table_id' => $tableId, ':completed' => 'completed']);
        $order = $stmt->fetch();

        if ($order) {
            return $this->markPaid((int)$order['id'], $paymentMethod, $changedByUserId);
        }

        // No active order, just free the table
        $this->pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
            ->execute([':status' => 'available', ':id' => $tableId]);

        return [
            'table_id' => $tableId,
            'status' => 'available',
            'order_id' => null,
        ];
    }
}
