<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

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
            if ($role !== 'admin') {
                http_response_code(403);
                json_response(['error' => 'Forbidden'], 403);
            }

            try {
                $revenueStmt = $pdo->query(
                    'SELECT SUM(oi.unit_price * oi.quantity) AS total_revenue,
                            SUM(oi.quantity) AS total_items,
                            COUNT(DISTINCT oi.order_id) AS completed_orders
                     FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id
                     WHERE o.status = "completed"'
                );
                $summary = $revenueStmt->fetch();

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
                     WHERE o.status = "completed"
                     GROUP BY mi.id, mi.name
                     ORDER BY quantity_sold DESC, item_name ASC
                     LIMIT 10'
                );
                $topItems = $topItemsStmt->fetchAll();

                json_response([
                    'total_revenue' => (float)($summary['total_revenue'] ?? 0),
                    'completed_orders' => (int)($summary['completed_orders'] ?? 0),
                    'items_sold' => (int)($summary['total_items'] ?? 0),
                    'sales' => $sales,
                    'top_items' => $topItems,
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
                'SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, o.table_id, o.created_at, o.special_instructions AS instructions
                FROM order_items oi
                LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                LEFT JOIN orders o ON o.id = oi.order_id
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
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :order_id')->execute([':status' => 'ready', ':order_id' => $orderItem['order_id']]);
            }
        } elseif ($newStatus === 'served') {
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status NOT IN (\'served\', \'completed\')');
            $checkStmt->execute([':order_id' => $orderItem['order_id']]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :order_id')->execute([':status' => 'served', ':order_id' => $orderItem['order_id']]);
            }
        } elseif ($newStatus === 'completed') {
            $pdo->prepare('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :order_id')->execute([':status' => 'completed', ':order_id' => $orderItem['order_id']]);
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
