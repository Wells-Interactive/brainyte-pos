<?php
declare(strict_types=1);
/**
 * API v1 - Status (Tables and Order Items)
 *
 * GET  /API/v1/status/index.php          - Get all tables and active order items
 * GET  /API/v1/status/index.php?stats=1  - Get admin statistics
 * POST /API/v1/status/index.php          - Update order item status or mark table paid
 *
 * This is the stable public API for the Flutter application.
 * Requires Bearer token authentication.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Inventory;

header('Content-Type: application/json; charset=utf-8');

// Require authentication
$authUser = require_auth();

$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];
// ============================================================
// GET - Tables and Order Items (or Stats)
// ============================================================
if ($method === 'GET') {
    // Admin statistics
    if (isset($_GET['stats']) && $_GET['stats'] === '1') {
        if (!in_array($authUser['role'], ['admin', 'owner', 'manager', 'supervisor'], true)) {
            http_response_code(403);
            json_response(['error' => 'Forbidden: insufficient permissions'], 403);
        }

        $now = set_now();
        $todayStart = date('Y-m-d 00:00:00');
        $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $monthStart = date('Y-m-01 00:00:00');

        try {
            $summaryStmt = $pdo->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN o.created_at >= '{$todayStart}' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS day_revenue,
                    COALESCE(SUM(CASE WHEN o.created_at >= '{$weekStart}' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS week_revenue,
                    COALESCE(SUM(CASE WHEN o.created_at >= '{$monthStart}' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS month_revenue,
                    COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS total_revenue,
                    COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.quantity ELSE 0 END), 0) AS total_items,
                    COALESCE(COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN oi.order_id END), 0) AS completed_orders,
                    COALESCE(COUNT(DISTINCT CASE WHEN oi.routed_to = 'bar' THEN oi.order_id END), 0) AS total_bar_orders,
                    COALESCE(COUNT(DISTINCT CASE WHEN oi.routed_to = 'kitchen' THEN oi.order_id END), 0) AS total_kitchen_orders,
                    COALESCE(COUNT(DISTINCT CASE WHEN o.status = 'pending' THEN o.id END), 0) AS pending_orders
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE " . demo_data_scope_sql('oi', $authUser) . ' AND ' . demo_data_scope_sql('o', $authUser)
            );
            $summary = $summaryStmt->fetch();

            $salesStmt = $pdo->query(
                "SELECT DATE(o.created_at) AS date, SUM(oi.unit_price * oi.quantity) AS total
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'completed' AND " . demo_data_scope_sql('oi', $authUser) . ' AND ' . demo_data_scope_sql('o', $authUser) . "
                 GROUP BY DATE(o.created_at)
                 ORDER BY date DESC
                 LIMIT 30"
            );
            $sales = $salesStmt->fetchAll();

            $topItemsStmt = $pdo->query(
                "SELECT mi.name AS item_name, SUM(oi.quantity) AS quantity_sold
                 FROM order_items oi
                 JOIN menu_items mi ON mi.id = oi.menu_item_id
                 JOIN orders o ON o.id = oi.order_id
                 WHERE o.status IN ('completed', 'served', 'ready', 'preparing', 'pending') AND " . demo_data_scope_sql('oi', $authUser) . ' AND ' . demo_data_scope_sql('mi', $authUser) . ' AND ' . demo_data_scope_sql('o', $authUser) . "
                 GROUP BY mi.id, mi.name
                 ORDER BY quantity_sold DESC, item_name ASC
                 LIMIT 10"
            );
            $topItems = $topItemsStmt->fetchAll();

            $tablesStmt = $pdo->query(
                'SELECT id, name, status FROM restaurant_tables WHERE ' . demo_data_scope_sql('restaurant_tables', $authUser) . ' ORDER BY id'
            );
            $tables = $tablesStmt->fetchAll();
            $tables = array_map(static function (array $table): array {
                $status = strtolower((string)($table['status'] ?? 'available'));
                return [
                    'id' => (int)$table['id'],
                    'name' => (string)($table['name'] ?? 'Table ' . $table['id']),
                    'status' => in_array($status, ['available', 'occupied', 'reserved', 'closed'], true) ? $status : 'available',
                ];
            }, $tables);

            json_response([
                'success' => true,
                'data' => [
                    'total_revenue' => (float)($summary['total_revenue'] ?? 0),
                    'completed_orders' => (int)($summary['completed_orders'] ?? 0),
                    'items_sold' => (int)($summary['total_items'] ?? 0),
                    'total_bar_orders' => (int)($summary['total_bar_orders'] ?? 0),
                    'total_kitchen_orders' => (int)($summary['total_kitchen_orders'] ?? 0),
                    'pending_orders' => (int)($summary['pending_orders'] ?? 0),
                    'summary_day' => (float)($summary['day_revenue'] ?? 0),
                    'summary_week' => (float)($summary['week_revenue'] ?? 0),
                    'summary_month' => (float)($summary['month_revenue'] ?? 0),
                    'sales' => $sales,
                    'top_items' => $topItems,
                    'tables' => $tables,
                ],
            ]);
        } catch (Throwable $exception) {
            error_log('Statistics load error: ' . $exception->getMessage());
            json_response(['success' => false, 'error' => 'Unable to load statistics'], 500);
        }
        return;
    }

    // Tables and active order items
    $tables = [];
    try {
        $rows = $pdo->query(
            'SELECT id, name, status FROM restaurant_tables WHERE ' . demo_data_scope_sql('restaurant_tables', $authUser) . ' ORDER BY id'
        )->fetchAll();
        $tableMap = [];
        foreach ($rows as $table) {
            $tableMap[(int)$table['id']] = $table;
        }
        if (!empty($authUser['is_demo'])) {
            $tables = array_values($rows);
        } else {
            for ($i = 1; $i <= 20; $i++) {
                if (isset($tableMap[$i])) {
                    $tables[] = $tableMap[$i];
                } else {
                    $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
                }
            }
        }
    } catch (Throwable $exception) {
        $tables = [];
    }

    if ($tables === [] && empty($authUser['is_demo'])) {
        for ($i = 1; $i <= 20; $i++) {
            $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
        }
    }

    $items = [];
    try {
        $stmt = $pdo->query(
            "SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, oi.created_at,
                    o.table_id, o.special_instructions AS instructions, o.created_at AS order_created_at, u.name AS waiter_name
             FROM order_items oi
             LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
             LEFT JOIN orders o ON o.id = oi.order_id
             LEFT JOIN users u ON u.id = o.waiter_id
             WHERE oi.status != 'completed' AND " . demo_data_scope_sql('oi', $authUser) . ' AND ' . demo_data_scope_sql('mi', $authUser) . ' AND ' . demo_data_scope_sql('o', $authUser) . ' AND ' . demo_data_scope_sql('u', $authUser) . "
             ORDER BY oi.id DESC
             LIMIT 200"
        );
        $items = $stmt->fetchAll();

        // Cast numeric fields
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['order_id'] = (int)$item['order_id'];
            $item['menu_item_id'] = (int)$item['menu_item_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['unit_price'] = (float)$item['unit_price'];
            $item['table_id'] = (int)$item['table_id'];
        }
    } catch (Throwable $exception) {
        $items = [];
    }

    json_response([
        'success' => true,
        'data' => [
            'tables' => $tables,
            'order_items' => $items,
        ],
    ]);
    return;
}
    // Tables and active order items - continued in next insert
// ============================================================
// POST - Update status or mark table paid
// ============================================================
if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $action = trim((string)($body['action'] ?? ''));
    $now = set_now();

    // Mark Table as Paid
    if ($action === 'mark_paid') {
        if (!in_array($authUser['role'], ['admin', 'owner', 'manager', 'supervisor', 'waiter'], true)) {
            json_response(['error' => 'Forbidden: insufficient permissions'], 403);
        }

        $tableId = isset($body['table_id']) ? (int)$body['table_id'] : 0;
        if ($tableId <= 0) {
            json_response(['error' => 'Table ID is required'], 400);
        }

        $paymentMethod = in_array(strtolower((string)($body['payment_method'] ?? 'pending')), ['cash', 'pos', 'transfer', 'pending'], true)
            ? strtolower((string)($body['payment_method'] ?? 'pending'))
            : 'pending';

        $pdo->beginTransaction();
        try {
            $tableStmt = $pdo->prepare('SELECT id FROM restaurant_tables WHERE id = :id LIMIT 1');
            $tableStmt->execute([':id' => $tableId]);
            if (!$tableStmt->fetch()) {
                $pdo->rollBack();
                json_response(['error' => 'Table not found'], 404);
            }

            // Mark all unpaid orders for this table as paid
            $updateStmt = $pdo->prepare(
                'UPDATE orders SET payment_status = :status, payment_method = :method, updated_at = :now 
                 WHERE table_id = :table_id AND payment_status != :exclude_status'
            );
            $updateStmt->execute([
                ':status' => 'paid',
                ':method' => $paymentMethod,
                ':now' => $now,
                ':table_id' => $tableId,
                ':exclude_status' => 'paid',
            ]);

            // Mark table as available
            $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $tableId]);

            $pdo->commit();
            json_response(['success' => true, 'data' => ['table_id' => $tableId, 'status' => 'available']]);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Mark paid error: ' . $exception->getMessage());
            json_response(['success' => false, 'error' => 'Unable to mark table as paid'], 500);
        }
        return;
    }

    // Update Order Item Status
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    $newStatus = trim((string)($body['status'] ?? ''));

    if ($orderId <= 0 || $newStatus === '') {
        json_response(['error' => 'Order ID and status are required'], 400);
    }

    $allowedStatuses = ['pending', 'preparing', 'ready', 'served', 'completed'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        json_response(['error' => 'Invalid status'], 400);
    }

    // Check permission
    $role = $authUser['role'];
    if ($role === 'kitchen' && $newStatus !== 'ready') {
        json_response(['error' => 'Kitchen can only mark items as ready'], 403);
    }
    if ($role === 'bar' && $newStatus !== 'ready') {
        json_response(['error' => 'Bar can only mark items as ready'], 403);
    }
    if ($role === 'waiter' && !in_array($newStatus, ['served', 'completed'], true)) {
        json_response(['error' => 'Waiter can only mark items as served or completed'], 403);
    }

    $pdo->beginTransaction();
    try {
        // Get the order item
        $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id AND status != :exclude LIMIT 1');
        $itemStmt->execute([':order_id' => $orderId, ':exclude' => 'completed']);
        $orderItem = $itemStmt->fetch();

        if (!$orderItem) {
            $pdo->rollBack();
            json_response(['error' => 'Order item not found'], 404);
        }

        // Update item status
        $pdo->prepare('UPDATE order_items SET status = :status, updated_at = :now WHERE id = :id')
            ->execute([':status' => $newStatus, ':now' => $now, ':id' => $orderItem['id']]);

        // Log status change
        log_order_status_history($pdo, $orderId, $orderItem['status'], $newStatus, $authUser['id'], 'Status updated via API');

        // Update order status based on item statuses
        if ($newStatus === 'ready') {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != 'ready'");
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'ready', ':updated_at' => $now, ':order_id' => $orderId]);
                log_order_status_history($pdo, $orderId, null, 'preparing', 'ready', $authUser['id'], 'All items ready');
                create_notification($pdo, 'waiter', null, "Order #{$orderId} Ready", "Order #{$orderId} is ready to serve", 'status_change', 'order', $orderId);
            }
        } elseif ($newStatus === 'served') {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status NOT IN ('served', 'completed')");
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'served', ':updated_at' => $now, ':order_id' => $orderId]);
                log_order_status_history($pdo, $orderId, null, 'ready', 'served', $authUser['id'], 'All items served');
            }
        } elseif ($newStatus === 'completed') {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != 'completed'");
            $checkStmt->execute([':order_id' => $orderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'completed', ':updated_at' => $now, ':order_id' => $orderId]);
                log_order_status_history($pdo, $orderId, null, 'served', 'completed', $authUser['id']);

                // Free table only if no other active orders remain for this table
                $tableStmt = $pdo->prepare('SELECT table_id FROM orders WHERE id = :id');
                $tableStmt->execute([':id' => $orderId]);
                $tableRow = $tableStmt->fetch();
                if ($tableRow) {
                    $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE table_id = :table_id AND status != 'completed' AND id != :exclude_id");
                    $activeStmt->execute([':table_id' => $tableRow['table_id'], ':exclude_id' => $orderId]);
                    $activeCount = (int)$activeStmt->fetchColumn();
                    if ($activeCount === 0) {
                        $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                            ->execute([':status' => 'available', ':id' => $tableRow['table_id']]);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log('Order status update error: ' . $exception->getMessage());
        json_response(['success' => false, 'error' => 'Unable to update order status'], 500);
    }

    json_response(['success' => true, 'data' => ['item_id' => $orderItem['id'] ?? 0, 'status' => $newStatus, 'order_id' => $orderId]]);
    return;
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);