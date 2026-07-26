<?php
declare(strict_types=1);
/**
 * API v1 - Orders
 *
 * POST /API/v1/orders/index.php - Create order
 *   Body: { table_id, items: [{ menu_item_id, quantity, instructions? }], order_instructions?, payment_method? }
 *
 * GET  /API/v1/orders/index.php - List orders
 *   Query: ?status=pending&role=kitchen&limit=50
 *   Query: ?id=123 (single order)
 *
 * This is the stable public API for the Flutter application.
 * The server determines price, name, category, and routing from the database.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Order;
use App\MenuItem;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();
$orderService = new Order($pdo);

// ============================================================
// POST - Create a new order
// ============================================================
if ($method === 'POST') {
    // Waiter, admin, and owner can create orders
    $authUser = require_role(['waiter', 'admin', 'owner']);

    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $tableId = isset($body['table_id']) ? (int)$body['table_id'] : 0;
    $items = $body['items'] ?? [];
    $orderInstructions = trim((string)($body['order_instructions'] ?? $body['instructions'] ?? '')) ?: null;

    if ($tableId <= 0 || !is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Table ID and items are required']);
        exit;
    }

    try {
        $result = $orderService->create([
            'table_id' => $tableId,
            'waiter_id' => $authUser['id'],
            'items' => $items,
            'instructions' => $orderInstructions,
            'payment_method' => $body['payment_method'] ?? 'pending',
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data' => $result,
            'error' => null,
            'meta' => null,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to create order: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// GET - List orders
// ============================================================
if ($method === 'GET') {
    $authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

    $status = trim((string)($_GET['status'] ?? ''));
    $role = trim((string)($_GET['role'] ?? $authUser['role']));
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 100;
    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    $filters = [
        'status' => $status,
        'role' => $role,
        'limit' => $limit,
        'order_id' => $orderId,
    ];

    $orders = $orderService->getOrders($filters);

    echo json_encode([
        'success' => true,
        'data' => $orders,
        'meta' => ['count' => count($orders)],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
