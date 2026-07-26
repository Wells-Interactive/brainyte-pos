<?php
declare(strict_types=1);
/**
 * Short-Polling Fallback Endpoint
 *
 * GET /API/Live_Events/poll.php?since=123&department=kitchen
 *
 * This provides a fallback for environments where SSE connections
 * are not reliable (e.g., shared hosting, mobile apps when backgrounded).
 *
 * Flutter fallback strategy:
 *   Primary: SSE (/API/Live%20Events/index.php)
 *   Fallback: Poll every 2-5 seconds using this endpoint
 *
 * Authentication:
 *   - The department/role is DERIVED from the authenticated user, NOT from URL
 *   - Admin/manager/supervisor can specify ?department= to view any stream
 *   - Kitchen/bar users are automatically routed to their own department
 *
 * IMPORTANT: The database is the source of truth. direct_printing does NOT
 * suppress polling events — printing is a separate concern from display.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\Permission;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Authenticate the user
$authUser = require_auth();

// Determine which department to poll:
// For kitchen/bar users, use their own role
// For admin/manager/supervisor, allow optional ?department= parameter
$department = $authUser['role'];
if (in_array($authUser['role'], ['admin', 'owner', 'manager', 'supervisor'], true)) {
    $requestedDept = trim((string)($_GET['department'] ?? ''));
    if (in_array($requestedDept, ['kitchen', 'bar'], true)) {
        $department = $requestedDept;
    }
} elseif (!in_array($authUser['role'], ['kitchen', 'bar'], true)) {
    // Unauthorized role
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: invalid role for this endpoint']);
    exit;
}

// Get the last known ID (since parameter)
$since = isset($_GET['since']) ? max(0, (int)$_GET['since']) : 0;
$limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;

$pdo = get_db();

// Always fetch new order items for this department.
// The database remains the source of truth regardless of direct_printing setting.
$stmt = $pdo->prepare(
    "SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price,
            oi.status, oi.routed_to, oi.created_at, oi.instructions,
            o.table_id, o.waiter_id, o.special_instructions AS order_instructions,
            u.name AS waiter_name
     FROM order_items oi
     JOIN menu_items mi ON mi.id = oi.menu_item_id
     JOIN orders o ON o.id = oi.order_id
     JOIN users u ON u.id = o.waiter_id
     WHERE oi.id > :since AND oi.routed_to = :department
     ORDER BY oi.id
     LIMIT :limit"
);
$stmt->bindValue(':since', $since, PDO::PARAM_INT);
$stmt->bindValue(':department', $department, PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

// Cast numeric fields
foreach ($items as &$item) {
    $item['id'] = (int)$item['id'];
    $item['order_id'] = (int)$item['order_id'];
    $item['menu_item_id'] = (int)$item['menu_item_id'];
    $item['quantity'] = (int)$item['quantity'];
    $item['unit_price'] = (float)$item['unit_price'];
    $item['table_id'] = (int)$item['table_id'];
    $item['waiter_id'] = (int)$item['waiter_id'];
}
unset($item);

// Get the latest ID for the next poll
$latestId = $since;
if (!empty($items)) {
    $lastItem = end($items);
    $latestId = (int)$lastItem['id'];
}

json_response([
    'success' => true,
    'data' => [
        'items' => $items,
        'department' => $department,
        'since' => $since,
        'latest_id' => $latestId,
        'has_more' => count($items) >= $limit,
    ],
    'meta' => [
        'count' => count($items),
        'timestamp' => time(),
    ],
]);
