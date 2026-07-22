<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'database';
const DB_USER = 'user';
const DB_PASS = 'pass';

/**
 * Add a column to a table if it doesn't exist.
 */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $pdo->quote($column)));
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
}

/**
 * Ensure the order_items status enum includes all values.
 */
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

/**
 * Ensure the restaurant_tables status enum includes all values.
 */
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

/**
 * Ensure the users role enum includes all values.
 */
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

/**
 * Ensure the menu_items category enum includes all values.
 */
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

/**
 * Create or update database tables.
 * Uses $now = date('Y-m-d H:i:s') for time handling as per requirements.
 */
function ensure_database_schema(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');

    // --- Users ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            email VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner') NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB
        SQL);

    // --- Restaurant Tables ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS restaurant_tables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            status ENUM('available', 'occupied', 'reserved', 'closed') NOT NULL DEFAULT 'available',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB
        SQL);

    // --- Menu Items ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            price DECIMAL(9,2) NOT NULL,
            category ENUM('beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes') NOT NULL,
            available TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB
        SQL);

    // --- Orders ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_id INT NOT NULL,
            waiter_id INT NOT NULL,
            status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
            special_instructions TEXT DEFAULT NULL,
            payment_method ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT,
            FOREIGN KEY (waiter_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB
        SQL);

    // --- Order Items ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            menu_item_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(9,2) NOT NULL,
            status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
            routed_to ENUM('kitchen', 'bar') NOT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB
        SQL);

    // --- Order Status History (track every status change) ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS order_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT DEFAULT NULL,
            order_item_id INT DEFAULT NULL,
            from_status VARCHAR(20) DEFAULT NULL,
            to_status VARCHAR(20) NOT NULL,
            changed_by_user_id INT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
            FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
        SQL);

    // --- Notifications Queue ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_role ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'all') NOT NULL DEFAULT 'all',
            target_user_id INT DEFAULT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            type ENUM('order_update', 'status_change', 'payment', 'system', 'alert') NOT NULL DEFAULT 'order_update',
            reference_type VARCHAR(50) DEFAULT NULL,
            reference_id INT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            sent_to_push TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
        SQL);

    // --- Auth Tokens (for Flutter Bearer Token auth) ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
        SQL);

    // --- Settings (Centralized) ---
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB
        SQL);

    // Ensure all columns exist (backward compatibility)
    ensure_column($pdo, 'menu_items', 'available', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column($pdo, 'menu_items', 'created_at', 'DATETIME NOT NULL');
    ensure_column($pdo, 'orders', 'special_instructions', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'orders', 'payment_method', "ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending'");
    ensure_column($pdo, 'orders', 'updated_at', 'DATETIME NOT NULL');
    ensure_column($pdo, 'order_items', 'created_at', 'DATETIME NOT NULL');
    ensure_user_role_enum($pdo);
    ensure_table_status_enum($pdo);
    ensure_menu_category_enum($pdo);
    ensure_order_item_status_enum($pdo);

    // --- Insert default settings ---
    $defaultSettings = [
        ['restaurant_name', '6th June POS'],
        ['logo_url', '/assets/images/brainyte-icon.png'],
        ['vat_rate', '0.00'],
        ['currency', 'NGN'],
        ['timezone', 'Africa/Lagos'],
        ['printer_type', 'thermal'],
        ['footer_text', 'Powered by Brainyte'],
        ['direct_printing', '0'],
    ];
    $settingStmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
    foreach ($defaultSettings as $setting) {
        $settingStmt->execute([':key' => $setting[0], ':value' => $setting[1], ':updated_at' => $now]);
    }

    // --- Demo Users ---
    $demoUsers = [
        ['name' => 'Waiter User', 'email' => 'waiter@restaurant.local', 'password' => 'waiter123', 'role' => 'waiter'],
        ['name' => 'Kitchen User', 'email' => 'kitchen@restaurant.local', 'password' => 'kitchen123', 'role' => 'kitchen'],
        ['name' => 'Bar User', 'email' => 'bar@restaurant.local', 'password' => 'bar123', 'role' => 'bar'],
        ['name' => 'Manager User', 'email' => 'manager@restaurant.local', 'password' => 'manager123', 'role' => 'manager'],
        ['name' => 'Supervisor User', 'email' => 'supervisor@restaurant.local', 'password' => 'supervisor123', 'role' => 'supervisor'],
        ['name' => 'Admin User', 'email' => 'admin@restaurant.local', 'password' => 'admin123', 'role' => 'admin'],
        ['name' => 'Bar Owner', 'email' => 'owner@restaurant.local', 'password' => 'owner123', 'role' => 'owner'],
    ];

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name, :email, :password_hash, :role, :created_at) ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = VALUES(role)');
    foreach ($demoUsers as $user) {
        $stmt->execute([
            ':name' => $user['name'],
            ':email' => $user['email'],
            ':password_hash' => password_hash($user['password'], PASSWORD_BCRYPT),
            ':role' => $user['role'],
            ':created_at' => $now,
        ]);
    }

    // --- Demo Tables (20 tables) ---
    $tableStmt = $pdo->prepare('INSERT IGNORE INTO restaurant_tables (id, name, status, created_at) VALUES (:id, :name, :status, :created_at)');
    for ($i = 1; $i <= 20; $i++) {
        $tableStmt->execute([':id' => $i, ':name' => "Table {$i}", ':status' => 'available', ':created_at' => $now]);
    }
}

/**
 * Get the PDO database connection (singleton).
 */
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
