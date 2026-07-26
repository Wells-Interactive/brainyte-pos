<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\MenuItem;

/**
 * Create a new order.
 * POST /API/Orders/create.php
 * 
 * Body: { table_id, items: [{ menu_item_id, quantity, instructions? }], order_instructions?, payment_method }
 * 
 * The server determines price, name, category, and routing from the database.
 * The client must NOT send unit_price.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$now = date('Y-m-d H:i:s');

// Get authenticated user (session or bearer)
$authUser = require_role(['waiter', 'admin', 'owner']);

$tableId = isset($body['table_id']) ? (int)$body['table_id'] : 0;
$items = $body['items'] ?? [];
$orderInstructions = trim((string)($body['order_instructions'] ?? $body['instructions'] ?? '')) ?: null;
$paymentMethod = in_array(strtolower((string)($body['payment_method'] ?? 'pending')), ['cash', 'pos', 'transfer', 'pending'], true)
    ? strtolower((string)($body['payment_method'] ?? 'pending'))
    : 'pending';
$waiterId = $authUser['id'];
$waiterName = $authUser['name'];

if ($tableId <= 0 || !is_array($items) || count($items) === 0 || $waiterId <= 0) {
    json_response(['error' => 'Table and order items are required'], 400);
}

$pdo = get_db();

// Check table exists
$tableStmt = $pdo->prepare('SELECT id, status FROM restaurant_tables WHERE id = :id LIMIT 1');
$tableStmt->execute([':id' => $tableId]);
$table = $tableStmt->fetch();

if (!$table) {
    $insertTableStmt = $pdo->prepare('INSERT INTO restaurant_tables (id, name, status, created_at) VALUES (:id, :name, :status, :created_at)');
    $insertTableStmt->execute([
        ':id' => $tableId,
        ':name' => "Table {$tableId}",
        ':status' => 'available',
        ':created_at' => $now,
    ]);
}

$pdo->beginTransaction();

try {
    // Create order
    $orderStmt = $pdo->prepare(
        'INSERT INTO orders (table_id, waiter_id, status, special_instructions, payment_method, created_at, updated_at)
         VALUES (:table_id, :waiter_id, :status, :instructions, :payment_method, :created_at, :updated_at)'
    );
    $orderStmt->execute([
        ':table_id' => $tableId,
        ':waiter_id' => $waiterId,
        ':status' => 'pending',
        ':instructions' => $orderInstructions,
        ':payment_method' => $paymentMethod,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Log order creation in history
    log_order_status_history($pdo, $orderId, null, null, 'pending', $waiterId, 'Order created');

    // DB-authoritative menu lookup
    $menuItemService = new MenuItem($pdo);
    $foodCategories = ['rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];

    $insertItemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, instructions, status, routed_to, created_at)
         VALUES (:order_id, :menu_item_id, :quantity, :unit_price, :instructions, :status, :routed_to, :created_at)'
    );

    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $itemInstructions = trim((string)($item['instructions'] ?? '')) ?: null;

        if ($menuItemId <= 0) {
            continue;
        }

        // Server determines price, name, category from DB (authoritative)
        $product = $menuItemService->getById($menuItemId);

        if (!$product) {
            continue;
        }

        // Server determines routing based on category
        $routedTo = in_array($product['category'], $foodCategories, true) ? 'kitchen' : 'bar';

        // Store unit_price as historical snapshot (server-authoritative)
        $insertItemStmt->execute([
            ':order_id' => $orderId,
            ':menu_item_id' => $menuItemId,
            ':quantity' => $quantity,
            ':unit_price' => $product['price'],
            ':instructions' => $itemInstructions,
            ':status' => 'pending',
            ':routed_to' => $routedTo,
            ':created_at' => $now,
        ]);

        $orderItemId = (int)$pdo->lastInsertId();

        // Log each item creation
        log_order_status_history($pdo, $orderId, $orderItemId, null, 'pending', $waiterId, "Item added (routed to {$routedTo})");

        // Create notifications for kitchen/bar
        create_notification(
            $pdo,
            $routedTo,
            null,
            "New {$routedTo} Order",
            "Table {$tableId}: {$product['name']} x{$quantity}",
            'order_update',
            'order_item',
            $orderItemId
        );
    }

    // Occupy table
    $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id')
        ->execute([':status' => 'occupied', ':id' => $tableId]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order creation error: ' . $exception->getMessage());
    json_response(['success' => false, 'error' => 'Unable to save order'], 500);
}

// Check if direct_printing is enabled
$directPrinting = false;
$kitchenItems = [];
$barItems = [];

try {
    $settingStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'direct_printing' LIMIT 1");
    $settingStmt->execute();
    $settingRow = $settingStmt->fetch();
    $directPrinting = ($settingRow && $settingRow['setting_value'] === '1');

    if ($directPrinting) {
        $itemsStmt = $pdo->prepare(
            'SELECT oi.id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price, oi.routed_to, o.table_id, oi.status
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.routed_to, oi.id'
        );
        $itemsStmt->execute([':order_id' => $orderId]);
        $savedItems = $itemsStmt->fetchAll();

        foreach ($savedItems as $savedItem) {
            $itemData = [
                'id' => (int)$savedItem['id'],
                'menu_item_id' => (int)$savedItem['menu_item_id'],
                'item_name' => $savedItem['item_name'],
                'quantity' => (int)$savedItem['quantity'],
                'unit_price' => (float)$savedItem['unit_price'],
                'table_id' => $savedItem['table_id'],
                'waiter_name' => $waiterName,
            ];
            if ($savedItem['routed_to'] === 'kitchen') {
                $kitchenItems[] = $itemData;
            } else {
                $barItems[] = $itemData;
            }
        }
    }
} catch (Throwable $e) {
    $directPrinting = false;
}

$response = [
    'success' => true,
    'data' => [
        'order_id' => $orderId,
        'created_at' => $now,
        'table_id' => $tableId,
        'instructions' => $orderInstructions,
        'waiter_name' => $waiterName,
        'waiter_id' => $waiterId,
    ],
    'error' => null,
    'meta' => null,
];

if ($directPrinting) {
    $response['data']['direct_print'] = true;
    $response['data']['kitchen_items'] = $kitchenItems;
    $response['data']['bar_items'] = $barItems;
}

json_response($response);
