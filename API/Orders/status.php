<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * Update order item / order status.
 * POST /API/Orders/status.php
 * 
 * Body: { item_id, status } or { order_id, status }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

// Authenticate (kitchen, bar, waiter, admin can update status)
$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);

$now = date('Y-m-d H:i:s');
$pdo = get_db();

$itemId = isset($body['item_id']) ? (int)$body['item_id'] : 0;
$orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
$newStatus = $body['status'] ?? '';
$paymentMethod = $body['payment_method'] ?? null;

if (!in_array($newStatus, ['pending', 'preparing', 'ready', 'served', 'completed'], true)) {
    json_response(['error' => 'Invalid status value'], 400);
}

// Handle payment method update (for mark_paid)
if ($paymentMethod !== null && $orderId > 0) {
    if (!in_array(strtolower($paymentMethod), ['cash', 'pos', 'transfer', 'pending'], true)) {
        json_response(['error' => 'Invalid payment method'], 400);
    }

    $pdo->beginTransaction();
    try {
        $oldStmt = $pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
        $oldStmt->execute([':id' => $orderId]);
        $oldOrder = $oldStmt->fetch();
        $oldStatus = $oldOrder ? $oldOrder['status'] : null;

        $pdo->prepare('UPDATE orders SET status = :status, payment_method = :payment_method, updated_at = :updated_at WHERE id = :order_id')
            ->execute([':status' => 'completed', ':payment_method' => strtolower($paymentMethod), ':updated_at' => $now, ':order_id' => $orderId]);

        log_order_status_history($pdo, $orderId, null, $oldStatus, 'completed', $authUser['id'], "Payment: {$paymentMethod}");

        // Update all items in this order
        $pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
            ->execute([':status' => 'completed', ':order_id' => $orderId]);

        // Free the table
        $tableStmt = $pdo->prepare('SELECT table_id FROM orders WHERE id = :id');
        $tableStmt->execute([':id' => $orderId]);
        $tableRow = $tableStmt->fetch();
        if ($tableRow) {
            $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                ->execute([':status' => 'available', ':id' => $tableRow['table_id']]);
        }

        $pdo->commit();
        json_response(['success' => true, 'data' => ['order_id' => $orderId, 'status' => 'completed', 'payment_method' => $paymentMethod]]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Unable to update payment status'], 500);
    }
}

// Handle item status update
if ($itemId > 0) {
    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare('SELECT order_id, status FROM order_items WHERE id = :id LIMIT 1');
        $itemStmt->execute([':id' => $itemId]);
        $orderItem = $itemStmt->fetch();

        if (!$orderItem) {
            $pdo->rollBack();
            json_response(['error' => 'Order item not found'], 404);
        }

        $oldStatus = $orderItem['status'];
        $orderItemOrderId = (int)$orderItem['order_id'];

        $pdo->prepare('UPDATE order_items SET status = :status WHERE id = :id')
            ->execute([':status' => $newStatus, ':id' => $itemId]);

        // Log status change
        log_order_status_history($pdo, $orderItemOrderId, $itemId, $oldStatus, $newStatus, $authUser['id']);

        // Create notification for relevant role
        $itemNameStmt = $pdo->prepare(
            'SELECT mi.name, oi.routed_to, o.table_id
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.id = :id'
        );
        $itemNameStmt->execute([':id' => $itemId]);
        $itemInfo = $itemNameStmt->fetch();

        if ($itemInfo) {
            $routedTo = $itemInfo['routed_to'];
            $tableId = $itemInfo['table_id'];

            // Notify waiter when item is ready
            if ($newStatus === 'ready') {
                create_notification(
                    $pdo,
                    'waiter',
                    null,
                    "Item Ready for Table {$tableId}",
                    "{$itemInfo['name']} from {$routedTo} is ready to serve",
                    'status_change',
                    'order_item',
                    $itemId
                );
            }
        }

        // Check if all items are at the new status (for order-level updates)
        if ($newStatus === 'ready') {
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != :status');
            $checkStmt->execute([':order_id' => $orderItemOrderId, ':status' => 'ready']);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'ready', ':updated_at' => $now, ':order_id' => $orderItemOrderId]);
                log_order_status_history($pdo, $orderItemOrderId, null, 'preparing', 'ready', $authUser['id'], 'All items ready');
                create_notification($pdo, 'waiter', null, "Order Ready", "Order #{$orderItemOrderId} is ready to serve", 'status_change', 'order', $orderItemOrderId);
            }
        } elseif ($newStatus === 'served') {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status NOT IN ('served', 'completed')");
            $checkStmt->execute([':order_id' => $orderItemOrderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'served', ':updated_at' => $now, ':order_id' => $orderItemOrderId]);
                log_order_status_history($pdo, $orderItemOrderId, null, 'ready', 'served', $authUser['id'], 'All items served');
            }
        } elseif ($newStatus === 'completed') {
            // Check if all items are completed
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND status != 'completed'");
            $checkStmt->execute([':order_id' => $orderItemOrderId]);
            $pendingCount = (int)$checkStmt->fetchColumn();
            if ($pendingCount === 0) {
                $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
                    ->execute([':status' => 'completed', ':updated_at' => $now, ':order_id' => $orderItemOrderId]);
                log_order_status_history($pdo, $orderItemOrderId, null, 'served', 'completed', $authUser['id']);

                // Free table
                $tableStmt = $pdo->prepare('SELECT table_id FROM orders WHERE id = :id');
                $tableStmt->execute([':id' => $orderItemOrderId]);
                $tableRow = $tableStmt->fetch();
                if ($tableRow) {
                    $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                        ->execute([':status' => 'available', ':id' => $tableRow['table_id']]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        json_response(['error' => 'Unable to update order status'], 500);
    }

    json_response(['success' => true, 'data' => ['item_id' => $itemId, 'status' => $newStatus]]);
}

// Handle full order status update
if ($orderId > 0) {
    $pdo->beginTransaction();
    try {
        $oldStmt = $pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
        $oldStmt->execute([':id' => $orderId]);
        $oldOrder = $oldStmt->fetch();
        $oldStatus = $oldOrder ? $oldOrder['status'] : null;

        $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :order_id')
            ->execute([':status' => $newStatus, ':updated_at' => $now, ':order_id' => $orderId]);

        // Also update all items in the order
        $pdo->prepare('UPDATE order_items SET status = :status WHERE order_id = :order_id')
            ->execute([':status' => $newStatus, ':order_id' => $orderId]);

        log_order_status_history($pdo, $orderId, null, $oldStatus, $newStatus, $authUser['id'], 'Bulk order status update');

        if ($newStatus === 'completed') {
            $tableStmt = $pdo->prepare('SELECT table_id FROM orders WHERE id = :id');
            $tableStmt->execute([':id' => $orderId]);
            $tableRow = $tableStmt->fetch();
            if ($tableRow) {
                $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
                    ->execute([':status' => 'available', ':id' => $tableRow['table_id']]);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        json_response(['error' => 'Unable to update order status'], 500);
    }

    json_response(['success' => true, 'data' => ['order_id' => $orderId, 'status' => $newStatus]]);
}

json_response(['error' => 'Item ID or Order ID required'], 400);
