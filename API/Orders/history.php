<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * Get order status history.
 * GET /API/Orders/history.php?order_id=X&item_id=Y
 */

date_default_timezone_set('Africa/Lagos');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$orderItemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

if ($orderId <= 0 && $orderItemId <= 0) {
    json_response(['error' => 'Order ID or Item ID is required'], 400);
}

$pdo = get_db();
$sql = 'SELECT h.*, u.name AS changed_by_name
        FROM order_status_history h
        LEFT JOIN users u ON u.id = h.changed_by_user_id
        WHERE 1 = 1';
$params = [];

if ($orderId > 0) {
    $sql .= ' AND h.order_id = :order_id';
    $params[':order_id'] = $orderId;
}
if ($orderItemId > 0) {
    $sql .= ' AND h.order_item_id = :order_item_id';
    $params[':order_item_id'] = $orderItemId;
}

$sql .= ' ORDER BY h.created_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$history = $stmt->fetchAll();

json_response([
    'success' => true,
    'data' => $history,
    'meta' => ['count' => count($history)],
]);
