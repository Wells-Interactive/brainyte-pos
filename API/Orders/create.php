<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * Create a new order.
 * POST /API/Orders/create.php
 * 
 * Body: { table_id, items: [{ menu_item_id, quantity, unit_price }], instructions, payment_method }
 */

date_default_timezone_set('Africa/Lagos');

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
$instructions = trim((string)($body['instructions'] ?? '')) ?: null;
$paymentMethod = in_array(strtolower((string)($body['payment_method'] ?? 'pending')), ['cash', 'pos', 'transfer', 'pending'], true)
    ? strtolower((string)($body['payment_method'] ?? 'pending'))
    : 'pending';
$waiterId = $authUser['id'];
$waiterName = $authUser['name'];

// Fallback menu items
$fallbackMenuItems = [
    101 => ['name' => 'Star Lager', 'price' => 700.00, 'category' => 'beer'],
    102 => ['name' => 'Hero Lager', 'price' => 650.00, 'category' => 'beer'],
    103 => ['name' => 'Gulder', 'price' => 750.00, 'category' => 'beer'],
    104 => ['name' => 'Amstel Malta', 'price' => 450.00, 'category' => 'malt'],
    105 => ['name' => 'Guinness Malta', 'price' => 500.00, 'category' => 'malt'],
    106 => ['name' => 'Coca-Cola', 'price' => 350.00, 'category' => 'soft-drinks'],
    107 => ['name' => 'Sprite', 'price' => 350.00, 'category' => 'soft-drinks'],
    108 => ['name' => 'Eva', 'price' => 200.00, 'category' => 'water'],
    109 => ['name' => 'Aquafina', 'price' => 220.00, 'category' => 'water'],
    110 => ['name' => 'Fearless', 'price' => 900.00, 'category' => 'energy-drinks'],
    111 => ['name' => 'Predator', 'price' => 950.00, 'category' => 'energy-drinks'],
    112 => ['name' => 'Five Alive', 'price' => 600.00, 'category' => 'juice'],
    113 => ['name' => 'Chi Exotic', 'price' => 650.00, 'category' => 'juice'],
    114 => ['name' => 'Jameson', 'price' => 9500.00, 'category' => 'spirits'],
    115 => ['name' => 'Black Label', 'price' => 9000.00, 'category' => 'spirits'],
    116 => ['name' => 'Smirnoff Ice', 'price' => 950.00, 'category' => 'ready-to-drink'],
    117 => ['name' => 'Bacardi Breezer', 'price' => 1000.00, 'category' => 'ready-to-drink'],
    201 => ['name' => 'Jollof Rice', 'price' => 2400.00, 'category' => 'rice'],
    202 => ['name' => 'Fried Rice', 'price' => 2500.00, 'category' => 'rice'],
    203 => ['name' => 'White Rice', 'price' => 2200.00, 'category' => 'rice'],
    204 => ['name' => 'Coconut Rice', 'price' => 2600.00, 'category' => 'rice'],
    301 => ['name' => 'Goat Meat Pepper Soup', 'price' => 3200.00, 'category' => 'pepper-soup'],
    302 => ['name' => 'Cow Tail Pepper Soup', 'price' => 3400.00, 'category' => 'pepper-soup'],
    303 => ['name' => 'Catfish Pepper Soup', 'price' => 3600.00, 'category' => 'pepper-soup'],
    304 => ['name' => 'Chicken Pepper Soup', 'price' => 3000.00, 'category' => 'pepper-soup'],
    305 => ['name' => 'Assorted Meat Pepper Soup', 'price' => 3500.00, 'category' => 'pepper-soup'],
    401 => ['name' => 'Catfish Grill', 'price' => 4200.00, 'category' => 'grills'],
    402 => ['name' => 'Ram Meat', 'price' => 4800.00, 'category' => 'grills'],
    403 => ['name' => 'Suya', 'price' => 2800.00, 'category' => 'grills'],
    404 => ['name' => 'Chicken Grill', 'price' => 3200.00, 'category' => 'grills'],
    405 => ['name' => 'Turkey Grill', 'price' => 3600.00, 'category' => 'grills'],
    406 => ['name' => 'Gizzard', 'price' => 2200.00, 'category' => 'grills'],
    501 => ['name' => 'Egusi Soup', 'price' => 3000.00, 'category' => 'soups'],
    502 => ['name' => 'Ogbono Soup', 'price' => 3000.00, 'category' => 'soups'],
    503 => ['name' => 'Vegetable Soup', 'price' => 2600.00, 'category' => 'soups'],
    504 => ['name' => 'Okra Soup', 'price' => 2800.00, 'category' => 'soups'],
    505 => ['name' => 'Oha Soup', 'price' => 3100.00, 'category' => 'soups'],
    601 => ['name' => 'Pounded Yam', 'price' => 1800.00, 'category' => 'swallow'],
    602 => ['name' => 'Eba', 'price' => 1600.00, 'category' => 'swallow'],
    603 => ['name' => 'Semovita', 'price' => 1700.00, 'category' => 'swallow'],
    604 => ['name' => 'Amala', 'price' => 1800.00, 'category' => 'swallow'],
    701 => ['name' => 'Plantain', 'price' => 1200.00, 'category' => 'extras'],
    702 => ['name' => 'Moi Moi', 'price' => 1400.00, 'category' => 'extras'],
    703 => ['name' => 'Coleslaw', 'price' => 800.00, 'category' => 'extras'],
    704 => ['name' => 'Salad', 'price' => 900.00, 'category' => 'extras'],
    705 => ['name' => 'French Fries', 'price' => 1000.00, 'category' => 'extras'],
];

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
        ':instructions' => $instructions,
        ':payment_method' => $paymentMethod,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Log order creation in history
    log_order_status_history($pdo, $orderId, null, null, 'pending', $waiterId, 'Order created');

    // Save order items
    $itemStmt = $pdo->prepare('SELECT id, price, category FROM menu_items WHERE id = :id AND available = 1 LIMIT 1');
    $insertItemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, status, routed_to, created_at)
         VALUES (:order_id, :menu_item_id, :quantity, :unit_price, :status, :routed_to, :created_at)'
    );

    $foodCategories = ['rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];

    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $quantity = max(1, (int)($item['quantity'] ?? 1));

        if ($menuItemId <= 0) {
            continue;
        }

        $itemStmt->execute([':id' => $menuItemId]);
        $product = $itemStmt->fetch();

        // Create missing fallback item
        if (!$product && isset($fallbackMenuItems[$menuItemId])) {
            $fallback = $fallbackMenuItems[$menuItemId];
            try {
                $insertMenuStmt = $pdo->prepare(
                    'INSERT IGNORE INTO menu_items (id, name, description, price, category, available, created_at)
                     VALUES (:id, :name, :description, :price, :category, 1, :created_at)'
                );
                $insertMenuStmt->execute([
                    ':id' => $menuItemId,
                    ':name' => $fallback['name'],
                    ':description' => '',
                    ':price' => $fallback['price'],
                    ':category' => $fallback['category'],
                    ':created_at' => $now,
                ]);
            } catch (Throwable $e) {
                // ignore
            }

            $itemStmt->execute([':id' => $menuItemId]);
            $product = $itemStmt->fetch();
        }

        if (!$product) {
            continue;
        }

        $routedTo = in_array($product['category'], $foodCategories, true) ? 'kitchen' : 'bar';

        $insertItemStmt->execute([
            ':order_id' => $orderId,
            ':menu_item_id' => $menuItemId,
            ':quantity' => $quantity,
            ':unit_price' => $product['price'],
            ':status' => 'pending',
            ':routed_to' => $routedTo,
            ':created_at' => $now,
        ]);

        $orderItemId = (int)$pdo->lastInsertId();

        // Log each item creation
        log_order_status_history($pdo, $orderId, $orderItemId, null, 'pending', $waiterId, "Item added (routed to {$routedTo})");

        // Create notifications for kitchen/bar
        $itemName = $product['name'] ?? $fallbackMenuItems[$menuItemId]['name'] ?? "Item #{$menuItemId}";
        create_notification(
            $pdo,
            $routedTo,
            null,
            "New {$routedTo} Order",
            "Table {$tableId}: {$itemName} x{$quantity}",
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
    json_response(['error' => 'Unable to save order', 'debug' => $exception->getMessage()], 500);
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
        'instructions' => $instructions,
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
