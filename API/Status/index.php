<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

date_default_timezone_set('Africa/Lagos');

$pdo = null;
try {
    $pdo = get_db();
} catch (Throwable $exception) {
    $pdo = null;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
        if (isset($_GET['stats']) && $_GET['stats'] === '1') {
            session_start();
            $role = $_SESSION['user']['role'] ?? null;
            if (!in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true)) {
                http_response_code(403);
                json_response(['error' => 'Forbidden'], 403);
            }

            $now = set_now();
            $todayStart = date('Y-m-d 00:00:00', strtotime($now));
            $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days', strtotime($todayStart)));
            $monthStart = date('Y-m-01 00:00:00', strtotime($now));

            try {
                $summaryStmt = $pdo->query(
                    'SELECT
                        COALESCE(SUM(CASE WHEN o.created_at >= "' . $todayStart . '" THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS day_revenue,
                        COALESCE(SUM(CASE WHEN o.created_at >= "' . $weekStart . '" THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS week_revenue,
                        COALESCE(SUM(CASE WHEN o.created_at >= "' . $monthStart . '" THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS month_revenue,
                        COALESCE(SUM(CASE WHEN o.status = "completed" THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS total_revenue,
                        COALESCE(SUM(CASE WHEN o.status = "completed" THEN oi.quantity ELSE 0 END), 0) AS total_items,
                        COALESCE(COUNT(DISTINCT CASE WHEN o.status = "completed" THEN oi.order_id END), 0) AS completed_orders,
                        COALESCE(COUNT(DISTINCT CASE WHEN oi.routed_to = "bar" THEN oi.order_id END), 0) AS total_bar_orders,
                        COALESCE(COUNT(DISTINCT CASE WHEN oi.routed_to = "kitchen" THEN oi.order_id END), 0) AS total_kitchen_orders,
                        COALESCE(COUNT(DISTINCT CASE WHEN o.status = "pending" THEN o.id END), 0) AS pending_orders
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id'
                );
                $summary = $summaryStmt->fetch();

                $salesStmt = $pdo->query(
                    'SELECT o.id AS order_id, o.table_id, o.updated_at AS completed_at,
                            SUM(oi.unit_price * oi.quantity) AS revenue,
                            SUM(oi.quantity) AS items_sold
                     FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id
                     WHERE o.status = "completed"
                     GROUP BY o.id, o.table_id, o.updated_at
                     ORDER BY o.updated_at DESC
                     LIMIT 20'
                );
                $sales = $salesStmt->fetchAll();

                $topItemsStmt = $pdo->query(
                    'SELECT mi.name AS item_name, SUM(oi.quantity) AS quantity_sold
                     FROM order_items oi
                     JOIN menu_items mi ON mi.id = oi.menu_item_id
                     JOIN orders o ON o.id = oi.order_id
                     WHERE o.status IN ("completed", "served", "ready", "preparing", "pending")
                     GROUP BY mi.id, mi.name
                     ORDER BY quantity_sold DESC, item_name ASC
                     LIMIT 10'
                );
                $topItems = $topItemsStmt->fetchAll();

                $tablesStmt = $pdo->query('SELECT id, name, status FROM restaurant_tables ORDER BY id');
                $tables = $tablesStmt->fetchAll();
                $tables = array_map(static function (array $table): array {
                    $status = strtolower((string)($table['status'] ?? 'available'));
                    return ['id' => (int)$table['id'], 'name' => (string)($table['name'] ?? 'Table ' . $table['id']), 'status' => in_array($status, ['available', 'occupied', 'reserved', 'closed'], true) ? $status : 'available'];
                }, $tables);

                json_response([
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
                ]);
            } catch (Throwable $exception) {
                json_response(['error' => 'Unable to load statistics'], 500);
            }
            return;
        }

    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query('SELECT id, name, status FROM restaurant_tables ORDER BY id')->fetchAll();
            $tableMap = [];
            foreach ($rows as $table) {
                $tableMap[(int)$table['id']] = $table;
            }

            for ($i = 1; $i <= 20; $i++) {
                if (isset($tableMap[$i])) {
                    $tables[] = $tableMap[$i];
                } else {
                    $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
                }
            }
        } catch (Throwable $exception) {
            $tables = [];
        }
    }

    if ($tables === []) {
        for ($i = 1; $i <= 20; $i++) {
            $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
        }
    }

    $items = [];
    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->query(
                'SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, o.table_id, o.created_at, o.special_instructions AS instructions, u.name AS waiter_name
                FROM order_items oi
                LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN users u ON u.id = o.waiter_id
                WHERE oi.status != "completed"
                ORDER BY oi.id DESC
                LIMIT 200'
            );
            $items = $stmt->fetchAll();
        } catch (Throwable $exception) {
            $items = [];
        }
    }

    json_response(['tables' => $tables, 'order_items' => $items]);
    return;
}

if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $action = trim((string)($body['action'] ?? ''));
    if ($action === 'mark_paid') {
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
            $table = $tableStmt->fetch();
            if (!$table) {
                throw new RuntimeException('Table not found');
            }

            $orderStmt = $pdo->prepare('SELECT id FROM orders WHERE table_id = :table_id AND status != :completed ORDER BY id DESC LIMIT 1');
            $orderStmt->execute([':table_id' => $tableId, ':completed' => 'completed']);
            $order = $orderStmt->fetch();
            if ($order) {
                    $now = date('Y-m-d H:i:s');
                    $pdo->prepare('UPDATE orders SET status = :status, payment_method = :payment_method, updated_at = :updated_at WHERE id = :order_id')
                        ->execute([':status' => 'completed', ':payment_method' => $paymentMethod, ':updated_at' => $now, ':order_id' => $order['id']]);
            }

            $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $tableId]);
            $pdo->commit();
            json_response(['success' => true, 'table_id' => $tableId, 'payment_method' => $paymentMethod]);
            return;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            json_response(['error' => 'Unable to mark table as paid'], 500);
        }
    }

    $itemId = isset($body['item_id']) ? (int)$body['item_id'] : 0;
    $newStatus = $body['status'] ?? '';
    if ($itemId <= 0 || !in_array($newStatus, ['preparing', 'ready', 'served', 'completed'], true)) {
        json_response(['error' => 'Invalid item update request'], 400);
    }

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare('SELECT order_id FROM order_items WHERE id = :id LIMIT 1');
        $itemStmt->execute([':id' => $itemId]);
        $orderItem = $itemStmt->fetch();
        if (!$orderItem) {
            throw new RuntimeException('Order item not found');
        }

        $update = $pdo->prepare('UPDATE order_items SET status = :status WHERE id = :id');
        $update->execute([':status' => $newStatus, ':id' => $itemId]);

        if ($newStatus === 'ready') {
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != :ready');
            $checkStmt->execute([':order_id' => $orderItem['order_id'], ':ready' => 'ready']);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $now = date('Y-m-d H:i:s');
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')->execute([':status' => 'ready', ':updated_at' => $now, ':order_id' => $orderItem['order_id']]);
            }
        } elseif ($newStatus === 'served') {
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status NOT IN (\'served\', \'completed\')');
            $checkStmt->execute([':order_id' => $orderItem['order_id']]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $now = date('Y-m-d H:i:s');
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')->execute([':status' => 'served', ':updated_at' => $now, ':order_id' => $orderItem['order_id']]);
            }
        } elseif ($newStatus === 'completed') {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')->execute([':status' => 'completed', ':updated_at' => $now, ':order_id' => $orderItem['order_id']]);
            $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = (SELECT table_id FROM orders WHERE id = :order_id)')
                ->execute([':status' => 'available', ':order_id' => $orderItem['order_id']]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        json_response(['error' => 'Unable to update order status'], 500);
    }

    json_response(['success' => true]);
    return;
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);
