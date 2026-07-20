<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'database';
const DB_USER = 'user';
const DB_PASS = 'pass';

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $pdo->quote($column)));
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
}

function ensure_order_item_status_enum(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'status'");
    $column = $stmt->fetch();
    if ($column === false) {
        return;
    }

    $type = (string)($column['Type'] ?? '');
    if (stripos($type, 'completed') === false) {
        $pdo->exec("ALTER TABLE order_items MODIFY status ENUM('pending','preparing','ready','served','completed') NOT NULL DEFAULT 'pending'");
    }
}

function ensure_table_status_enum(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM restaurant_tables LIKE 'status'");
    $column = $stmt->fetch();
    if ($column === false) {
        return;
    }

    $type = (string)($column['Type'] ?? '');
    if (stripos($type, 'reserved') === false) {
        $pdo->exec("ALTER TABLE restaurant_tables MODIFY status ENUM('available','occupied','reserved','closed') NOT NULL DEFAULT 'available'");
    }
}

function ensure_user_role_enum(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $stmt->fetch();
    if ($column === false) {
        return;
    }

    $type = (string)($column['Type'] ?? '');
    if (stripos($type, 'manager') === false || stripos($type, 'supervisor') === false || stripos($type, 'owner') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('waiter','kitchen','bar','manager','supervisor','admin','owner') NOT NULL");
    }
}

function ensure_menu_category_enum(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM menu_items LIKE 'category'");
    $column = $stmt->fetch();
    if ($column === false) {
        return;
    }

    $type = (string)($column['Type'] ?? '');
    if (stripos($type, 'cigarettes') === false) {
        $pdo->exec("ALTER TABLE menu_items MODIFY category ENUM('beer','malt','soft-drinks','water','energy-drinks','juice','spirits','ready-to-drink','rice','pepper-soup','grills','soups','swallow','extras','cigarettes') NOT NULL");
    }
}

function ensure_database_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            email VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS restaurant_tables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            status ENUM('available', 'occupied', 'reserved', 'closed') NOT NULL DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            price DECIMAL(9,2) NOT NULL,
            category ENUM('beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes') NOT NULL,
            available TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_id INT NOT NULL,
            waiter_id INT NOT NULL,
            status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
            special_instructions TEXT DEFAULT NULL,
            payment_method ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT,
            FOREIGN KEY (waiter_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            menu_item_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(9,2) NOT NULL,
            status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
            routed_to ENUM('kitchen', 'bar') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB
        SQL);

    ensure_column($pdo, 'menu_items', 'available', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column($pdo, 'menu_items', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    ensure_column($pdo, 'orders', 'special_instructions', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'orders', 'payment_method', "ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending'");
    ensure_column($pdo, 'orders', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    ensure_user_role_enum($pdo);
    ensure_table_status_enum($pdo);
    ensure_menu_category_enum($pdo);
    ensure_order_item_status_enum($pdo);

    $demoUsers = [
        ['name' => 'Waiter User', 'email' => 'waiter@restaurant.local', 'password' => 'waiter123', 'role' => 'waiter'],
        ['name' => 'Kitchen User', 'email' => 'kitchen@restaurant.local', 'password' => 'kitchen123', 'role' => 'kitchen'],
        ['name' => 'Bar User', 'email' => 'bar@restaurant.local', 'password' => 'bar123', 'role' => 'bar'],
        ['name' => 'Manager User', 'email' => 'manager@restaurant.local', 'password' => 'manager123', 'role' => 'manager'],
        ['name' => 'Supervisor User', 'email' => 'supervisor@restaurant.local', 'password' => 'supervisor123', 'role' => 'supervisor'],
        ['name' => 'Admin User', 'email' => 'admin@restaurant.local', 'password' => 'admin123', 'role' => 'admin'],
        ['name' => 'Bar Owner', 'email' => 'owner@restaurant.local', 'password' => 'owner123', 'role' => 'owner'],
    ];

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role) ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = VALUES(role)');
    foreach ($demoUsers as $user) {
        $stmt->execute([
            ':name' => $user['name'],
            ':email' => $user['email'],
            ':password_hash' => password_hash($user['password'], PASSWORD_BCRYPT),
            ':role' => $user['role'],
        ]);
    }

    $pdo->exec("INSERT IGNORE INTO restaurant_tables (id, name, status) VALUES
        (1, 'Table 1', 'available'),
        (2, 'Table 2', 'available'),
        (3, 'Table 3', 'available'),
        (4, 'Table 4', 'available'),
        (5, 'Table 5', 'available'),
        (6, 'Table 6', 'available'),
        (7, 'Table 7', 'available'),
        (8, 'Table 8', 'available'),
        (9, 'Table 9', 'available'),
        (10, 'Table 10', 'available'),
        (11, 'Table 11', 'available'),
        (12, 'Table 12', 'available'),
        (13, 'Table 13', 'available'),
        (14, 'Table 14', 'available'),
        (15, 'Table 15', 'available'),
        (16, 'Table 16', 'available'),
        (17, 'Table 17', 'available'),
        (18, 'Table 18', 'available'),
        (19, 'Table 19', 'available'),
        (20, 'Table 20', 'available')");
}

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    ensure_database_schema($pdo);
    return $pdo;
}
