<?php
declare(strict_types=1);

const DEMO_OWNER_EMAIL = 'demo.owner@brainyte.local';
const DEMO_OWNER_PASSWORD = 'Demo123@&';

function demo_data_scope_sql(string $alias, ?array $user = null): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid demo scope table alias.');
    }

    static $resolvedUser;
    static $userResolved = false;
    if ($user === null) {
        if (!$userResolved) {
            $resolvedUser = function_exists('get_auth_user') ? (get_auth_user() ?? []) : [];
            $userResolved = true;
        }
        $user = $resolvedUser ?? [];
    }
    $column = $alias . '.demo_owner_id';
    if (!$user) {
        return $column . ' IS NULL';
    }
    if (!empty($user['is_demo'])) {
        return $column . ' = ' . (int)$user['id'];
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return '1 = 1';
    }
    $restaurantId = (int)($user['restaurant_id'] ?? 0);
    if ($restaurantId > 0) {
        if (in_array($alias, ['menu_items', 'mi', 'm'], true)) {
            return restaurant_menu_scope_sql($alias, $user);
        }
        return $alias . '.restaurant_id = ' . $restaurantId;
    }
    if (($user['role'] ?? '') === 'customer') {
        return $column . ' IS NULL';
    }
    return '1 = 0';
}

function restaurant_menu_scope_sql(string $alias, ?array $user = null): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid menu scope table alias.');
    }

    $user ??= function_exists('get_auth_user') ? (get_auth_user() ?? []) : [];
    if (!$user) {
        return demo_data_scope_sql($alias, []);
    }
    if (!empty($user['is_demo'])) {
        return demo_data_scope_sql($alias, $user);
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return '1 = 1';
    }

    $restaurantId = (int)($user['restaurant_id'] ?? 0);
    if ($restaurantId <= 0) {
        if (($user['role'] ?? '') === 'customer') {
            return demo_data_scope_sql($alias, []);
        }
        return '1 = 0';
    }

    return '(' . $alias . '.restaurant_id = ' . $restaurantId
        . ' OR ' . $alias . '.chain_id = (SELECT chain_id FROM restaurants WHERE id = ' . $restaurantId . ' AND chain_id IS NOT NULL))';
}

function ensure_demo_scope_schema(PDO $pdo): void
{
    $tables = [
        'users',
        'restaurant_tables',
        'menu_items',
        'orders',
        'order_items',
        'order_status_history',
        'notifications',
        'print_jobs',
        'delivery_orders',
        'delivery_tracking',
        'audit_logs',
        'inventory_items',
        'inventory_movements',
        'suppliers',
        'supply_receipts',
        'supply_receipt_items',
        'table_close_evidence',
        'order_cancellation_requests',
    ];

    foreach ($tables as $table) {
        ensure_column($pdo, $table, 'demo_owner_id', 'INT DEFAULT NULL');
    }
}

function assert_demo_dataset_consistent(PDO $pdo, int $ownerId): void
{
    $owner = $pdo->prepare(
        'SELECT COUNT(*) FROM users
         WHERE id = :owner_id AND is_demo = 1 AND role = :role AND demo_owner_id = :scope_owner_id'
    );
    $owner->execute([':owner_id' => $ownerId, ':role' => 'owner', ':scope_owner_id' => $ownerId]);
    if ((int)$owner->fetchColumn() !== 1) {
        throw new RuntimeException('The demo owner record is inconsistent.');
    }

    $staff = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND demo_owner_id = :owner_id');
    $staff->execute([':owner_id' => $ownerId]);
    if ((int)$staff->fetchColumn() < 7) {
        throw new RuntimeException('The demo users are incomplete.');
    }

    $tables = $pdo->prepare('SELECT COUNT(*) FROM restaurant_tables WHERE demo_owner_id = :owner_id');
    $tables->execute([':owner_id' => $ownerId]);
    if ((int)$tables->fetchColumn() < 20) {
        throw new RuntimeException('The demo tables are incomplete.');
    }

    $menu = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE demo_owner_id = :owner_id AND name LIKE 'Demo - %'");
    $menu->execute([':owner_id' => $ownerId]);
    if ((int)$menu->fetchColumn() < 8) {
        throw new RuntimeException('The demo menu is incomplete.');
    }

    $inventory = $pdo->prepare(
        'SELECT COUNT(*) FROM inventory_items ii
         JOIN menu_items mi ON mi.id = ii.menu_item_id
         WHERE ii.demo_owner_id = :owner_id AND mi.demo_owner_id = :menu_owner_id'
    );
    $inventory->execute([':owner_id' => $ownerId, ':menu_owner_id' => $ownerId]);
    if ((int)$inventory->fetchColumn() < 8) {
        throw new RuntimeException('The demo inventory is incomplete.');
    }

    $orders = $pdo->prepare(
        "SELECT o.total_amount, o.paid_amount, o.payment_status,
                COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS items_total
         FROM orders o
         LEFT JOIN order_items oi
           ON oi.order_id = o.id AND oi.demo_owner_id = :item_owner_id
         WHERE o.demo_owner_id = :order_owner_id
           AND o.special_instructions IN ('demo-seed-order-1', 'demo-seed-order-2')
         GROUP BY o.id, o.total_amount, o.paid_amount, o.payment_status"
    );
    $orders->execute([':item_owner_id' => $ownerId, ':order_owner_id' => $ownerId]);
    $seededOrders = $orders->fetchAll();
    if (count($seededOrders) !== 2) {
        throw new RuntimeException('The demo orders are incomplete.');
    }
    foreach ($seededOrders as $order) {
        if (abs((float)$order['total_amount'] - (float)$order['items_total']) > 0.01) {
            throw new RuntimeException('A demo order total does not match its items.');
        }
        if (
            ($order['payment_status'] === 'paid' && abs((float)$order['paid_amount'] - (float)$order['total_amount']) > 0.01)
            || ($order['payment_status'] === 'unpaid' && (float)$order['paid_amount'] !== 0.0)
        ) {
            throw new RuntimeException('A demo order payment total is inconsistent.');
        }
    }

    $delivery = $pdo->prepare(
        'SELECT COUNT(*) FROM delivery_orders
         WHERE demo_owner_id = :owner_id AND special_requests = :request'
    );
    $delivery->execute([':owner_id' => $ownerId, ':request' => 'demo-seed-delivery']);
    if ((int)$delivery->fetchColumn() !== 1) {
        throw new RuntimeException('The demo delivery order is missing or duplicated.');
    }
}

function ensure_demo_dataset(PDO $pdo): int
{
    ensure_demo_scope_schema($pdo);

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $email = DEMO_OWNER_EMAIL;
        $userQuery = $pdo->prepare('SELECT id, is_demo FROM users WHERE LOWER(email) = :email LIMIT 1');
        $userQuery->execute([':email' => $email]);
        $existing = $userQuery->fetch();

        if ($existing && !(bool)$existing['is_demo']) {
            throw new RuntimeException('The reserved demo owner email is already used by a regular account.');
        }

        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash(DEMO_OWNER_PASSWORD, PASSWORD_BCRYPT);
        if ($existing) {
            $ownerId = (int)$existing['id'];
            $update = $pdo->prepare(
                'UPDATE users
                 SET name = :name, password_hash = :password_hash, role = :role, demo_owner_id = :owner_id
                 WHERE id = :id AND is_demo = 1'
            );
            $update->execute([
                ':name' => 'Brainyte Demo Owner',
                ':password_hash' => $passwordHash,
                ':role' => 'owner',
                ':owner_id' => $ownerId,
                ':id' => $ownerId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_demo, demo_owner_id, created_at)
                 VALUES (:name, :email, :password_hash, :role, 1, NULL, :created_at)'
            );
            $insert->execute([
                ':name' => 'Brainyte Demo Owner',
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':role' => 'owner',
                ':created_at' => $now,
            ]);
            $ownerId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE users SET demo_owner_id = :owner_id WHERE id = :owner_id')
                ->execute([':owner_id' => $ownerId]);
        }

        $staff = [];
        foreach ([
            ['Demo Waiter', 'demo.waiter@brainyte.local', 'waiter'],
            ['Demo Kitchen', 'demo.kitchen@brainyte.local', 'kitchen'],
            ['Demo Bar', 'demo.bar@brainyte.local', 'bar'],
            ['Demo Manager', 'demo.manager@brainyte.local', 'manager'],
            ['Demo Customer', 'demo.customer@brainyte.local', 'customer'],
            ['Demo Rider', 'demo.rider@brainyte.local', 'rider'],
        ] as [$name, $staffEmail, $role]) {
            $staffQuery = $pdo->prepare('SELECT id, is_demo FROM users WHERE LOWER(email) = :email LIMIT 1');
            $staffQuery->execute([':email' => $staffEmail]);
            $staffUser = $staffQuery->fetch();
            if ($staffUser && !(bool)$staffUser['is_demo']) {
                throw new RuntimeException("The reserved demo user email {$staffEmail} is already in use.");
            }

            if ($staffUser) {
                $staffId = (int)$staffUser['id'];
                $pdo->prepare(
                    'UPDATE users SET name = :name, role = :role, demo_owner_id = :owner_id WHERE id = :id AND is_demo = 1'
                )->execute([
                    ':name' => $name,
                    ':role' => $role,
                    ':owner_id' => $ownerId,
                    ':id' => $staffId,
                ]);
            } else {
                $staffInsert = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role, is_demo, demo_owner_id, created_at)
                     VALUES (:name, :email, :password_hash, :role, 1, :owner_id, :created_at)'
                );
                $staffInsert->execute([
                    ':name' => $name,
                    ':email' => $staffEmail,
                    ':password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
                    ':role' => $role,
                    ':owner_id' => $ownerId,
                    ':created_at' => $now,
                ]);
                $staffId = (int)$pdo->lastInsertId();
            }
            $staff[$role] = $staffId;
        }

        $tables = [];
        $tableIdStart = max(
            1_000_000,
            (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM restaurant_tables')->fetchColumn()
        ) + 1;
        for ($number = 1; $number <= 20; $number++) {
            $name = 'Table ' . $number;
            $query = $pdo->prepare(
                'SELECT id FROM restaurant_tables WHERE name = :name AND demo_owner_id = :owner_id LIMIT 1'
            );
            $query->execute([':name' => $name, ':owner_id' => $ownerId]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO restaurant_tables (id, name, status, demo_owner_id, created_at)
                     VALUES (:id, :name, :status, :owner_id, :created_at)'
                );
                $insert->execute([
                    ':id' => $tableIdStart + $number - 1,
                    ':name' => $name,
                    ':status' => $number === 1 ? 'occupied' : ($number === 2 ? 'reserved' : 'available'),
                    ':owner_id' => $ownerId,
                    ':created_at' => $now,
                ]);
                $id = $pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id AND demo_owner_id = :owner_id')
                    ->execute([
                        ':status' => $number === 1 ? 'occupied' : ($number === 2 ? 'reserved' : 'available'),
                        ':id' => (int)$id,
                        ':owner_id' => $ownerId,
                    ]);
            }
            $tables[$name] = (int)$id;
        }

        $menu = [];
        foreach ([
            ['Jollof Rice', 'Smoky Nigerian party jollof rice.', 3500, 'rice', 'kitchen'],
            ['Egusi Soup', 'Egusi with assorted meat and a choice of swallow.', 5000, 'soups', 'kitchen'],
            ['Grilled Chicken', 'Seasoned grilled chicken with sides.', 6500, 'grills', 'kitchen'],
            ['Catfish Pepper Soup', 'Fresh catfish in spicy pepper soup.', 7000, 'pepper-soup', 'kitchen'],
            ['Chips', 'Golden crispy potato chips.', 2500, 'extras', 'kitchen'],
            ['Heineken', 'Chilled premium lager.', 1800, 'beer', 'bar'],
            ['Coke', 'Coca-Cola served chilled.', 1000, 'soft-drinks', 'bar'],
            ['Chapman', 'House-made Nigerian Chapman.', 2500, 'ready-to-drink', 'bar'],
        ] as [$name, $description, $price, $category, $department]) {
            $query = $pdo->prepare(
                'SELECT id FROM menu_items WHERE name = :name AND demo_owner_id = :owner_id LIMIT 1'
            );
            $query->execute([':name' => 'Demo - ' . $name, ':owner_id' => $ownerId]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO menu_items (name, description, price, category, available, demo_owner_id, created_at)
                     VALUES (:name, :description, :price, :category, 1, :owner_id, :created_at)'
                );
                $insert->execute([
                    ':name' => 'Demo - ' . $name,
                    ':description' => $description,
                    ':price' => $price,
                    ':category' => $category,
                    ':owner_id' => $ownerId,
                    ':created_at' => $now,
                ]);
                $id = $pdo->lastInsertId();
            }
            $menu[$name] = ['id' => (int)$id, 'price' => (float)$price, 'department' => $department];

            $inventoryQuery = $pdo->prepare('SELECT id FROM inventory_items WHERE menu_item_id = :menu_item_id LIMIT 1');
            $inventoryQuery->execute([':menu_item_id' => (int)$id]);
            $inventoryId = $inventoryQuery->fetchColumn();
            if ($inventoryId === false) {
                $inventoryInsert = $pdo->prepare(
                    'INSERT INTO inventory_items (menu_item_id, current_stock, min_stock_level, unit, demo_owner_id, created_at, updated_at)
                     VALUES (:menu_item_id, :stock, :minimum, :unit, :owner_id, :created_at, :updated_at)'
                );
                $inventoryInsert->execute([
                    ':menu_item_id' => (int)$id,
                    ':stock' => 28,
                    ':minimum' => 5,
                    ':unit' => 'portions',
                    ':owner_id' => $ownerId,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $inventoryId = $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO inventory_movements
                     (inventory_item_id, type, quantity, previous_qty, new_qty, reason, performed_by, demo_owner_id, created_at)
                     VALUES (:inventory_id, :type, :quantity, 0, :stock, :reason, :user_id, :owner_id, :created_at)'
                )->execute([
                    ':inventory_id' => (int)$inventoryId,
                    ':type' => 'stock_in',
                    ':quantity' => 28,
                    ':stock' => 28,
                    ':reason' => 'Initial demonstration stock',
                    ':user_id' => $ownerId,
                    ':owner_id' => $ownerId,
                    ':created_at' => $now,
                ]);
            }
        }

        $supplierQuery = $pdo->prepare('SELECT id FROM suppliers WHERE name = :name AND demo_owner_id = :owner_id LIMIT 1');
        $supplierQuery->execute([':name' => 'Demo - Fresh Foods Ltd', ':owner_id' => $ownerId]);
        $supplierId = $supplierQuery->fetchColumn();
        if ($supplierId === false) {
            $supplierInsert = $pdo->prepare(
                'INSERT INTO suppliers (name, contact_name, phone_number, email, address, notes, demo_owner_id, created_at, updated_at)
                 VALUES (:name, :contact, :phone, :email, :address, :notes, :owner_id, :created_at, :updated_at)'
            );
            $supplierInsert->execute([
                ':name' => 'Demo - Fresh Foods Ltd',
                ':contact' => 'Ada Okafor',
                ':phone' => '+2348000000100',
                ':email' => 'fresh-foods@demo.brainyte.local',
                ':address' => 'Ikeja, Lagos',
                ':notes' => 'Sample supplier record',
                ':owner_id' => $ownerId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $supplierId = $pdo->lastInsertId();
        }

        $orderIds = [];
        foreach ([
            ['Table 1', 'pending', 'unpaid', 9000, 0],
            ['Table 2', 'completed', 'paid', 9000, 9000],
        ] as $index => [$tableName, $status, $paymentStatus, $total, $paid]) {
            $orderDate = date('Y-m-d H:i:s', time() - ($index === 0 ? 3600 : 86400));
            $orderQuery = $pdo->prepare(
                'SELECT id FROM orders WHERE demo_owner_id = :owner_id AND special_instructions = :instructions LIMIT 1'
            );
            $instructions = 'demo-seed-order-' . ($index + 1);
            $orderQuery->execute([':owner_id' => $ownerId, ':instructions' => $instructions]);
            $orderId = $orderQuery->fetchColumn();
            if ($orderId === false) {
                $orderInsert = $pdo->prepare(
                    'INSERT INTO orders
                     (table_id, waiter_id, status, special_instructions, payment_method, payment_status, total_amount, paid_amount, demo_owner_id, created_at, updated_at)
                     VALUES (:table_id, :waiter_id, :status, :instructions, :payment_method, :payment_status, :total, :paid, :owner_id, :created_at, :updated_at)'
                );
                $orderInsert->execute([
                    ':table_id' => $tables[$tableName],
                    ':waiter_id' => $staff['waiter'],
                    ':status' => $status,
                    ':instructions' => $instructions,
                    ':payment_method' => $index === 0 ? 'pending' : 'cash',
                    ':payment_status' => $paymentStatus,
                    ':total' => $total,
                    ':paid' => $paid,
                    ':owner_id' => $ownerId,
                    ':created_at' => $orderDate,
                    ':updated_at' => $orderDate,
                ]);
                $orderId = $pdo->lastInsertId();
            }
            $orderIds[] = (int)$orderId;
        }

        $itemInsert = $pdo->prepare(
            'INSERT INTO order_items
             (order_id, menu_item_id, quantity, unit_price, instructions, status, routed_to, demo_owner_id, created_at, updated_at)
             SELECT :order_id, :menu_item_id, :quantity, :unit_price, :instructions, :status, :routed_to, :owner_id, :created_at, :updated_at
             WHERE NOT EXISTS (
                 SELECT 1 FROM order_items WHERE order_id = :check_order_id AND menu_item_id = :check_menu_item_id
             )'
        );
        foreach ([
            [$orderIds[0], 'Jollof Rice', 2, 'pending'],
            [$orderIds[0], 'Coke', 2, 'pending'],
            [$orderIds[1], 'Grilled Chicken', 1, 'completed'],
            [$orderIds[1], 'Chapman', 1, 'completed'],
        ] as [$orderId, $menuName, $quantity, $status]) {
            $menuItem = $menu[$menuName];
            $itemInsert->execute([
                ':order_id' => $orderId,
                ':menu_item_id' => $menuItem['id'],
                ':quantity' => $quantity,
                ':unit_price' => $menuItem['price'],
                ':instructions' => null,
                ':status' => $status,
                ':routed_to' => $menuItem['department'],
                ':owner_id' => $ownerId,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':check_order_id' => $orderId,
                ':check_menu_item_id' => $menuItem['id'],
            ]);
        }

        $customerId = $staff['customer'];
        $pdo->prepare(
            'INSERT INTO customer_profiles (user_id, phone_number, email_verified_at, created_at, updated_at)
             VALUES (:user_id, :phone, :verified, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number)'
        )->execute([
            ':user_id' => $customerId,
            ':phone' => '+2348000000200',
            ':verified' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $deliveryQuery = $pdo->prepare(
            'SELECT id FROM delivery_orders WHERE customer_id = :customer_id AND special_requests = :request LIMIT 1'
        );
        $deliveryQuery->execute([':customer_id' => $customerId, ':request' => 'demo-seed-delivery']);
        if ($deliveryQuery->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO delivery_orders
                 (customer_id, rider_id, items_json, total_amount, delivery_address, phone_number, special_requests, status, payment_method, demo_owner_id, created_at, updated_at)
                 VALUES (:customer_id, :rider_id, :items, :total, :address, :phone, :request, :status, :payment_method, :owner_id, :created_at, :updated_at)'
            )->execute([
                ':customer_id' => $customerId,
                ':rider_id' => $staff['rider'],
                ':items' => json_encode([['name' => 'Demo - Jollof Rice', 'quantity' => 2]], JSON_THROW_ON_ERROR),
                ':total' => 7000,
                ':address' => '12 Demo Street, Ikeja, Lagos',
                ':phone' => '+2348000000200',
                ':request' => 'demo-seed-delivery',
                ':status' => 'out_for_delivery',
                ':payment_method' => 'cash',
                ':owner_id' => $ownerId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

        assert_demo_dataset_consistent($pdo, $ownerId);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $ownerId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
