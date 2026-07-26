<?php
declare(strict_types=1);
/**
 * API v1 - Order Status History
 *
 * GET /API/v1/orders/history.php?order_id=123       - History for an order
 * GET /API/v1/orders/history.php?item_id=456        - History for an order item
 * GET /API/v1/orders/history.php?order_id=123&limit=20
 *
 * This is the stable public API for the Flutter application.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$orderItemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

if ($orderId <= 0 && $orderItemId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Order ID or Item ID is required']);
    exit;
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

// Cast numeric fields
foreach ($history as &$entry) {
    $entry['id'] = (int)$entry['id'];
    if (isset($entry['order_id'])) $entry['order_id'] = (int)$entry['order_id'];
    if (isset($entry['order_item_id'])) $entry['order_item_id'] = (int)$entry['order_item_id'];
    if (isset($entry['changed_by_user_id'])) $entry['changed_by_user_id'] = (int)$entry['changed_by_user_id'];
}
unset($entry);

echo json_encode([
    'success' => true,
    'data' => $history,
    'meta' => ['count' => count($history)],
]);
