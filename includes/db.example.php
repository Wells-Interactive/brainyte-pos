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
    if (stripos($type, 'customer') === false || stripos($type, 'rider') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('waiter','kitchen','bar','manager','supervisor','admin','owner','customer','rider') NOT NULL");
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
 */
function ensure_database_schema(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');

    // Users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'customer', 'rider') NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    // Restaurant Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_tables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        status ENUM('available', 'occupied', 'reserved', 'closed') NOT NULL DEFAULT 'available',
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    // Menu Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        price DECIMAL(9,2) NOT NULL,
        category ENUM('beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes') NOT NULL,
        available TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    // Orders (with payment_status, total_amount, paid_amount)
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_id INT NOT NULL,
        waiter_id INT NOT NULL,
        status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
        special_instructions TEXT DEFAULT NULL,
        payment_method ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending',
        payment_status ENUM('unpaid', 'partial', 'paid', 'refunded', 'voided') NOT NULL DEFAULT 'unpaid',
        total_amount DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_orders_table_status (table_id, status),
        INDEX idx_orders_waiter_created (waiter_id, created_at),
        INDEX idx_orders_status_created (status, created_at),
        INDEX idx_orders_created (created_at),
        INDEX idx_orders_payment_status (payment_status),
        FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT,
        FOREIGN KEY (waiter_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    // Order Items (with updated_at and indexes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        menu_item_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(9,2) NOT NULL,
        instructions TEXT DEFAULT NULL,
        status ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
        routed_to ENUM('kitchen', 'bar') NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_order_items_order (order_id),
        INDEX idx_order_items_routed_status (routed_to, status),
        INDEX idx_order_items_created (created_at),
        INDEX idx_order_items_order_routed (order_id, routed_to),
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    // Order Status History (with indexes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT DEFAULT NULL,
        order_item_id INT DEFAULT NULL,
        from_status VARCHAR(20) DEFAULT NULL,
        to_status VARCHAR(20) NOT NULL,
        changed_by_user_id INT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_osh_order_created (order_id, created_at),
        INDEX idx_osh_item_created (order_item_id, created_at),
        INDEX idx_osh_created (created_at),
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
        FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // Notifications (with indexes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
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
        INDEX idx_notifications_role_read_created (target_role, is_read, created_at),
        INDEX idx_notifications_user_read (target_user_id, is_read),
        INDEX idx_notifications_type (type),
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // Auth Tokens (with refresh_token, device_name, refresh_expires_at)
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        refresh_token VARCHAR(64) DEFAULT NULL,
        device_name VARCHAR(255) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        refresh_expires_at DATETIME DEFAULT NULL,
        last_used_at DATETIME DEFAULT NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_auth_tokens_user (user_id),
        INDEX idx_auth_tokens_expires (expires_at),
        INDEX idx_auth_tokens_refresh (refresh_token),
        INDEX idx_auth_tokens_user_revoked (user_id, revoked),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Print Jobs
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_item_id INT NOT NULL,
        order_id INT NOT NULL,
        department ENUM('kitchen', 'bar') NOT NULL,
        printer VARCHAR(50) NOT NULL DEFAULT 'default',
        status ENUM('pending', 'printing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        printed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_print_jobs_status (status),
        INDEX idx_print_jobs_order (order_id),
        INDEX idx_print_jobs_department_status (department, status),
        INDEX idx_print_jobs_created (created_at),
        FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Home delivery: profiles, email verification and rider location tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_profiles (
        user_id INT PRIMARY KEY, phone_number VARCHAR(30) NOT NULL,
        email_verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verification_codes (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL,
        INDEX idx_evc_user_expires (user_id, expires_at), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_orders (
        id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, rider_id INT DEFAULT NULL, items_json JSON NOT NULL,
        total_amount DECIMAL(9,2) NOT NULL DEFAULT 0.00, delivery_address TEXT NOT NULL, phone_number VARCHAR(30) NOT NULL,
        alternate_phone_number VARCHAR(30) DEFAULT NULL, special_requests TEXT DEFAULT NULL,
        latitude DECIMAL(10,7) DEFAULT NULL, longitude DECIMAL(10,7) DEFAULT NULL,
        status ENUM('requested','accepted','preparing','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'requested',
        payment_method ENUM('cash','pos','transfer','pending') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        INDEX idx_delivery_customer_created (customer_id, created_at), INDEX idx_delivery_rider_status (rider_id, status), INDEX idx_delivery_status_created (status, created_at),
        FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_tracking (
        id INT AUTO_INCREMENT PRIMARY KEY, delivery_order_id INT NOT NULL, rider_id INT NOT NULL,
        latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL, accuracy_meters DECIMAL(8,2) DEFAULT NULL, recorded_at DATETIME NOT NULL,
        INDEX idx_tracking_delivery_recorded (delivery_order_id, recorded_at),
        FOREIGN KEY (delivery_order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE, FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Schema Migrations Tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        batch INT NOT NULL DEFAULT 1,
        executed_at DATETIME NOT NULL,
        INDEX idx_migrations (migration)
    ) ENGINE=InnoDB");

    // Settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    // Audit Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        action VARCHAR(100) NOT NULL,
        resource_type VARCHAR(50) DEFAULT NULL,
        resource_id INT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_audit_user (user_id),
        INDEX idx_audit_action (action),
        INDEX idx_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Rate Limits
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(100) NOT NULL,
        type ENUM('api', 'login') NOT NULL DEFAULT 'api',
        hits INT NOT NULL DEFAULT 1,
        window_start DATETIME NOT NULL,
        blocked_until DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_rate_type_id (type, identifier, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Inventory Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        menu_item_id INT NOT NULL,
        current_stock DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        min_stock_level DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        unit VARCHAR(30) NOT NULL DEFAULT 'pieces',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_inventory_menu_item (menu_item_id),
        INDEX idx_inventory_stock_level (current_stock),
        INDEX idx_inventory_min_stock (min_stock_level),
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Inventory Movements
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        type ENUM('stock_in', 'stock_out', 'adjustment') NOT NULL,
        quantity DECIMAL(9,2) NOT NULL,
        previous_qty DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        new_qty DECIMAL(9,2) NOT NULL DEFAULT 0.00,
        reference_type VARCHAR(50) DEFAULT NULL,
        reference_id INT DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        performed_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_im_inventory_item (inventory_item_id),
        INDEX idx_im_type (type),
        INDEX idx_im_created (created_at),
        INDEX idx_im_reference (reference_type, reference_id),
        FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Supplier receiving, table-close evidence, cancellation approvals, password reset and push subscriptions.
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, contact_name VARCHAR(120) DEFAULT NULL, phone_number VARCHAR(30) DEFAULT NULL, email VARCHAR(120) DEFAULT NULL, address TEXT DEFAULT NULL, notes TEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_suppliers_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS supply_receipts (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT NOT NULL, reference_number VARCHAR(100) DEFAULT NULL, received_by_user_id INT NOT NULL, receipt_file_path VARCHAR(500) DEFAULT NULL, notes TEXT DEFAULT NULL, received_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX idx_supply_supplier_received (supplier_id, received_at), FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT, FOREIGN KEY (received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS supply_receipt_items (id INT AUTO_INCREMENT PRIMARY KEY, supply_receipt_id INT NOT NULL, inventory_item_id INT NOT NULL, quantity DECIMAL(9,2) NOT NULL, unit_cost DECIMAL(9,2) DEFAULT NULL, INDEX idx_supply_receipt_item (supply_receipt_id), FOREIGN KEY (supply_receipt_id) REFERENCES supply_receipts(id) ON DELETE CASCADE, FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS table_close_evidence (id INT AUTO_INCREMENT PRIMARY KEY, table_id INT NOT NULL, order_id INT NOT NULL, waiter_id INT NOT NULL, image_path VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_tce_table_created (table_id, created_at), FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT, FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT, FOREIGN KEY (waiter_id) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_cancellation_requests (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, requested_by_user_id INT NOT NULL, reason TEXT NOT NULL, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', reviewed_by_user_id INT DEFAULT NULL, review_notes TEXT DEFAULT NULL, created_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, UNIQUE KEY uq_cancel_request_order_pending (order_id, status), FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_codes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_prc_user_expires (user_id, expires_at), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, platform ENUM('web','android','ios') NOT NULL, token VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_push_subscription_token (token), INDEX idx_push_user_platform (user_id, platform), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Initialize inventory records for existing menu items
    try {
        $pdo->exec("INSERT IGNORE INTO inventory_items (menu_item_id, current_stock, min_stock_level, unit, created_at, updated_at)
                     SELECT id, 0, 10, 'pieces', NOW(), NOW() FROM menu_items");
    } catch (Throwable $e) {
        // Table may not exist yet during first setup
    }

    // Ensure backward-compatible columns
    ensure_column($pdo, 'menu_items', 'available', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column($pdo, 'menu_items', 'created_at', 'DATETIME NOT NULL');
    ensure_column($pdo, 'orders', 'special_instructions', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'orders', 'payment_method', "ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending'");
    ensure_column($pdo, 'orders', 'payment_status', "ENUM('unpaid', 'partial', 'paid', 'refunded', 'voided') NOT NULL DEFAULT 'unpaid'");
    ensure_column($pdo, 'orders', 'total_amount', 'DECIMAL(9,2) NOT NULL DEFAULT 0.00');
    ensure_column($pdo, 'orders', 'paid_amount', 'DECIMAL(9,2) NOT NULL DEFAULT 0.00');
    ensure_column($pdo, 'orders', 'updated_at', 'DATETIME NOT NULL');
    ensure_column($pdo, 'order_items', 'instructions', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'order_items', 'created_at', 'DATETIME NOT NULL');
    ensure_column($pdo, 'order_items', 'updated_at', 'DATETIME DEFAULT NULL');
    ensure_column($pdo, 'order_items', 'routed_to', "ENUM('kitchen', 'bar') NOT NULL");
    ensure_column($pdo, 'auth_tokens', 'refresh_token', 'VARCHAR(64) DEFAULT NULL');
    ensure_column($pdo, 'auth_tokens', 'device_name', 'VARCHAR(255) DEFAULT NULL');
    ensure_column($pdo, 'auth_tokens', 'refresh_expires_at', 'DATETIME DEFAULT NULL');
    ensure_user_role_enum($pdo);
    ensure_table_status_enum($pdo);
    ensure_menu_category_enum($pdo);
    ensure_order_item_status_enum($pdo);

    // Default settings only (no demo users in production)
    $defaultSettings = [
        ['restaurant_name', 'Restaurant POS'],
        ['logo_url', '/assets/images/brainyte-icon.png'],
        ['vat_rate', '0.00'],
        ['currency', 'NGN'],
        ['timezone', 'Africa/Lagos'],
        ['printer_type', 'thermal'],
        ['footer_text', 'Powered by Brainyte'],
        ['direct_printing', '0'],
        ['home_delivery_enabled', '0'],
    ];
    $settingStmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
    foreach ($defaultSettings as $setting) {
        $settingStmt->execute([':key' => $setting[0], ':value' => $setting[1], ':updated_at' => $now]);
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

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_NAME
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ];

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        $options
    );

    return $pdo;
}
