<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * List orders with filtering.
 * GET /API/Orders/list.php?status=pending&role=kitchen&limit=50
 */

date_default_timezone_set('Africa/Lagos');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

$status = $_GET['status'] ?? '';
$role = $_GET['role'] ?? '';  // 'kitchen' or 'bar' for filtering by routed_to
$limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 100;
$includeItems = ($_GET['include_items'] ?? '1') === '1';

$pdo = get_db();

$sql = 'SELECT o.id, o.table_id, o.waiter_id, o.status, o.special_instructions AS instructions,
               o.payment_method, o.created_at, o.updated_at,
               u.name AS waiter_name
        FROM orders o
        JOIN users u ON u.id = o.waiter_id
        WHERE 1 = 1';
$params = [];

if (in_array($status, ['pending', 'preparing', 'ready', 'served', 'completed'], true)) {
    $sql .= ' AND o.status = :status';
    $params[':status'] = $status;
}

$sql .= ' ORDER BY o.created_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$ordersData = [];
foreach ($orders as $order) {
    $orderId = (int)$order['id'];

    // Get order items
    $itemSql = 'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, oi.created_at
                FROM order_items oi
                JOIN menu_items mi ON mi.id = oi.menu_item_id
                WHERE oi.order_id = :order_id';

    $itemParams = [':order_id' => $orderId];

    if ($role === 'kitchen' || $role === 'bar') {
        $itemSql .= ' AND oi.routed_to = :role';
        $itemParams[':role'] === $role;
    }

    $itemSql .= ' ORDER BY oi.id';

    $itemStmt = $pdo->prepare($itemSql);

    // Rebuild params without the reference issue
    if ($role === 'kitchen' || $role === 'bar') {
        $itemStmt2 = $pdo->prepare(
            'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, oi.created_at
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = :order_id2 AND oi.routed_to = :role2
             ORDER BY oi.id'
        );
        $itemStmt2->execute([':order_id2' => $orderId, ':role2' => $role]);
        $items = $itemStmt2->fetchAll();
    } else {
        $itemStmt = $pdo->prepare(
            'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.status, oi.routed_to, oi.created_at
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = :order_id2
             ORDER BY oi.id'
        );
        $itemStmt->execute([':order_id2' => $orderId]);
        $items = $itemStmt->fetchAll();
    }

    $order['items'] = $items;
    $order['id'] = $orderId;
    $order['table_id'] = (int)$order['table_id'];
    $order['waiter_id'] = (int)$order['waiter_id'];
    $ordersData[] = $order;
}

json_response([
    'success' => true,
    'data' => $ordersData,
    'meta' => ['count' => count($ordersData)],
]);
