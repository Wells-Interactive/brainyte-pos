<?php
declare(strict_types=1);
/**
 * API v1 - Order Status Updates
 *
 * POST /API/v1/orders/status.php
 *   Body: { item_id: 123, status: 'preparing'|'ready'|'served'|'completed' }
 *   Body: { order_id: 456, status: 'preparing'|'ready'|'served'|'completed' }
 *   Body: { order_id: 456, payment_method: 'cash'|'pos'|'transfer' } (mark as paid)
 *
 * This is the stable public API for the Flutter application.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

try {
    $body = get_json_body();
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$itemId = isset($body['item_id']) ? (int)$body['item_id'] : 0;
$orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
$newStatus = trim((string)($body['status'] ?? ''));
$paymentMethod = isset($body['payment_method']) ? trim((string)$body['payment_method']) : null;

$allowedStatuses = ['pending', 'preparing', 'ready', 'served', 'completed'];
if (!in_array($newStatus, $allowedStatuses, true) && $paymentMethod === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses)]);
    exit;
}

$pdo = get_db();
$now = date('Y-m-d H:i:s');

// ============================================================
// Handle payment (mark as paid)
// ============================================================
if ($paymentMethod !== null && $orderId > 0) {
    $allowedPaymentMethods = ['cash', 'pos', 'transfer', 'pending'];
    $paymentMethod = strtolower($paymentMethod);
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment method']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT status, table_id FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        $oldStatus = $order['status'];

        $pdo->prepare('UPDATE orders SET status = :status, payment_method = :payment_method, payment_status = :payment_status, updated_at = :updated_at WHERE id = :order_id')
            ->execute([
                ':status' => 'completed',
                ':payment_method' => $paymentMethod,
                ':payment_status' => 'paid',
                ':updated_at' => $now,
                ':order_id' => $orderId,
            ]);

        $pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
            ->execute([':status' => 'completed', ':order_id' => $orderId]);

        log_order_status_history($pdo, $orderId, null, $oldStatus, 'completed', $authUser['id'], "Payment: {$paymentMethod}");

        // Free the table if no other active orders
        $tableId = (int)$order['table_id'];
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE table_id = :table_id AND status != 'completed' AND id != :exclude_id");
        $activeStmt->execute([':table_id' => $tableId, ':exclude_id' => $orderId]);
        $activeCount = (int)$activeStmt->fetchColumn();
        if ($activeCount === 0) {
            $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $tableId]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'data' => [
                'order_id' => $orderId,
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
            ],
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to process payment']);
        error_log('v1 payment error: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
// Handle item status update
// ============================================================
if ($itemId > 0) {
    try {
        $orderService = new App\Order($pdo);
        $result = $orderService->updateItemStatus($itemId, $newStatus, $authUser['id']);

        echo json_encode([
            'success' => true,
            'data' => $result,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        error_log('v1 item status error: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
// Handle full order status update
// ============================================================
if ($orderId > 0) {
    try {
        $orderService = new App\Order($pdo);
        $result = $orderService->updateOrderStatus($orderId, $newStatus, $authUser['id']);

        echo json_encode([
            'success' => true,
            'data' => $result,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        error_log('v1 order status error: ' . $e->getMessage());
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Item ID or Order ID required']);
