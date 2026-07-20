<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

date_default_timezone_set('Africa/Lagos');


session_start();
$waiterRole = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$waiterId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
$waiterName = trim((string)($_SESSION['username'] ?? $_SESSION['user']['name'] ?? ''));

if ($waiterRole !== 'waiter') {
    http_response_code(403);
    json_response(['error' => 'Forbidden'], 403);
}

$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $role = $_GET['role'] ?? '';
    $status = $_GET['status'] ?? '';
    $sql = 'SELECT o.id, o.table_id, o.status, o.created_at, o.updated_at, u.name AS waiter_name
            FROM orders o
            JOIN users u ON u.id = o.waiter_id
            WHERE 1 = 1';
    $params = [];
    if (in_array($status, ['pending', 'preparing', 'ready', 'served', 'completed'], true)) {
        $sql .= ' AND o.status = :status';
        $params[':status'] = $status;
    }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $itemStmt = $pdo->prepare('SELECT oi.id, oi.menu_item_id, mi.name, oi.quantity, oi.unit_price, oi.status, oi.routed_to FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :order_id ORDER BY oi.id');
        $itemStmt->execute([':order_id' => $order['id']]);
        $order['items'] = $itemStmt->fetchAll();
    }

    json_response(['orders' => $orders]);
    return;
}

if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

 	/*
       Generate time AFTER request starts processing
       This ensures correct Nigerian time
    */
    $now = date('Y-m-d H:i:s');

    $tableId = isset($body['table_id']) ? (int)$body['table_id'] : 0;
    $items = $body['items'] ?? [];
    $instructions = trim((string)($body['instructions'] ?? '')) ?: null;
    $paymentMethod = in_array(strtolower((string)($body['payment_method'] ?? 'pending')), ['cash', 'pos', 'transfer', 'pending'], true)
        ? strtolower((string)($body['payment_method'] ?? 'pending'))
        : 'pending';
    $waiterId = (int)($_SESSION['user']['id'] ?? 0);
    $waiterName = trim((string)($_SESSION['user']['name'] ?? ''));

    $hasInstructionsColumn = false;
    try {
        $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'special_instructions'");
        $columnStmt->execute();
        $hasInstructionsColumn = (int)$columnStmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        $hasInstructionsColumn = false;
    }

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


    /*
       Check table exists
    */

    $tableStmt = $pdo->prepare(
        'SELECT id, status 
         FROM restaurant_tables 
         WHERE id = :id 
         LIMIT 1'
    );

    $tableStmt->execute([
        ':id' => $tableId
    ]);

    $table = $tableStmt->fetch();


    if (!$table) {

        $insertTableStmt = $pdo->prepare(
            'INSERT INTO restaurant_tables 
            (id, name, status) 
            VALUES 
            (:id, :name, :status)'
        );


        $insertTableStmt->execute([
            ':id' => $tableId,
            ':name' => "Table {$tableId}",
            ':status' => 'available'
        ]);
    }



    /*
       SAVE ORDER
    */

    $pdo->beginTransaction();


    try {


        /*
          Correct timestamp insert
          Using PHP $now instead of MySQL NOW()
        */

        if ($hasInstructionsColumn) {


            $orderStmt = $pdo->prepare(
                '
                INSERT INTO orders
                (
                    table_id,
                    waiter_id,
                    status,
                    special_instructions,
                    payment_method,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :table_id,
                    :waiter_id,
                    :status,
                    :instructions,
                    :payment_method,
                    :created_at,
                    :updated_at
                )
                '
            );


            $orderStmt->execute([

                ':table_id' => $tableId,

                ':waiter_id' => $waiterId,

                ':status' => 'pending',

                ':instructions' => $instructions,

                ':payment_method' => $paymentMethod,

                ':created_at' => $now,

                ':updated_at' => $now
            ]);



        } else {


            $orderStmt = $pdo->prepare(
                '
                INSERT INTO orders
                (
                    table_id,
                    waiter_id,
                    status,
                    payment_method,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :table_id,
                    :waiter_id,
                    :status,
                    :payment_method,
                    :created_at,
                    :updated_at
                )
                '
            );


            $orderStmt->execute([

                ':table_id' => $tableId,

                ':waiter_id' => $waiterId,

                ':status' => 'pending',

                ':payment_method' => $paymentMethod,

                ':created_at' => $now,

                ':updated_at' => $now
            ]);
        }



        $orderId = (int)$pdo->lastInsertId();



        /*
           Save order items
        */


        $itemStmt = $pdo->prepare(
            '
            SELECT 
                id,
                price,
                category
            FROM menu_items
            WHERE id = :id
            AND available = 1
            LIMIT 1
            '
        );



        $insertItemStmt = $pdo->prepare(
            '
            INSERT INTO order_items
            (
                order_id,
                menu_item_id,
                quantity,
                unit_price,
                status,
                routed_to
            )
            VALUES
            (
                :order_id,
                :menu_item_id,
                :quantity,
                :unit_price,
                :status,
                :routed_to
            )
            '
        );



        foreach ($items as $item) {


            $menuItemId = (int)(
                $item['menu_item_id'] ?? 0
            );


            $quantity = max(
                1,
                (int)(
                    $item['quantity'] ?? 1
                )
            );


            if ($menuItemId <= 0) {
                continue;
            }



            $itemStmt->execute([
                ':id' => $menuItemId
            ]);


            $product = $itemStmt->fetch();



            /*
               Create missing fallback item
            */

            if (!$product && isset($fallbackMenuItems[$menuItemId])) {


                $fallback = $fallbackMenuItems[$menuItemId];


                try {


                    $insertMenuStmt = $pdo->prepare(
                        '
                        INSERT IGNORE INTO menu_items
                        (
                            id,
                            name,
                            description,
                            price,
                            category,
                            available
                        )
                        VALUES
                        (
                            :id,
                            :name,
                            :description,
                            :price,
                            :category,
                            1
                        )
                        '
                    );


                    $insertMenuStmt->execute([

                        ':id' => $menuItemId,

                        ':name' => $fallback['name'],

                        ':description' => '',

                        ':price' => $fallback['price'],

                        ':category' => $fallback['category']
                    ]);


                } catch (Throwable $e) {

                    // ignore fallback creation error
                }



                $itemStmt->execute([
                    ':id' => $menuItemId
                ]);


                $product = $itemStmt->fetch();

            }



            if (!$product) {
                continue;
            }



            /*
               Route food to kitchen
               Drinks to bar
            */

            $foodCategories = [

                'rice',
                'pepper-soup',
                'grills',
                'soups',
                'swallow',
                'extras'

            ];



            $routedTo = in_array(
                $product['category'],
                $foodCategories,
                true
            )
            ? 'kitchen'
            : 'bar';



            $insertItemStmt->execute([

                ':order_id' => $orderId,

                ':menu_item_id' => $menuItemId,

                ':quantity' => $quantity,

                ':unit_price' => $product['price'],

                ':status' => 'pending',

                ':routed_to' => $routedTo

            ]);
        }




        /*
           Occupy table
        */

        $updateTableStmt = $pdo->prepare(
            '
            UPDATE restaurant_tables
            SET status = :status
            WHERE id = :id
            '
        );


        $updateTableStmt->execute([

            ':status' => 'occupied',

            ':id' => $tableId

        ]);



        $pdo->commit();



    } catch (Throwable $exception) {


        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }


        json_response([

            'error' => 'Unable to save order',

            // remove in production
            'debug' => $exception->getMessage()

        ],500);


        exit;
    }




    json_response([

        'success' => true,

        'order_id' => $orderId,

        'created_at' => $now

    ]);


    exit;

}




http_response_code(405);

json_response([

    'error' => 'Method not allowed'

],405);


