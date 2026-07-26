-- =============================================================================
-- Brainyte Restaurant POS - Complete Database Schema
-- =============================================================================
-- This file serves as the reference schema matching includes/db.example.php
-- and all migration files (001-004).
--
-- The canonical source of truth is the migration files in:
--   database/migrations/
--
-- db.example.php provides a dynamic "CREATE TABLE IF NOT EXISTS" approach
-- that is convenient for development but should NOT replace migrations in production.
-- =============================================================================

-- Users
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(80) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner') NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Restaurant Tables
CREATE TABLE IF NOT EXISTS `restaurant_tables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `status` ENUM('available', 'occupied', 'reserved', 'closed') NOT NULL DEFAULT 'available',
    `created_at` DATETIME NOT NULL,
    INDEX `idx_restaurant_tables_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Menu Items
CREATE TABLE IF NOT EXISTS `menu_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `price` DECIMAL(9,2) NOT NULL,
    `category` ENUM('beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes') NOT NULL,
    `available` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_menu_items_category` (`category`),
    INDEX `idx_menu_items_available` (`available`),
    INDEX `idx_menu_items_category_available` (`category`, `available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Orders (with payment_status, total_amount, paid_amount)
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `table_id` INT NOT NULL,
    `waiter_id` INT NOT NULL,
    `status` ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
    `special_instructions` TEXT DEFAULT NULL,
    `payment_method` ENUM('cash', 'pos', 'transfer', 'pending') NOT NULL DEFAULT 'pending',
    `payment_status` ENUM('unpaid', 'partial', 'paid', 'refunded', 'voided') NOT NULL DEFAULT 'unpaid',
    `total_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_orders_table_status` (`table_id`, `status`),
    INDEX `idx_orders_waiter_created` (`waiter_id`, `created_at`),
    INDEX `idx_orders_status_created` (`status`, `created_at`),
    INDEX `idx_orders_created` (`created_at`),
    INDEX `idx_orders_payment_status` (`payment_status`),
    CONSTRAINT `fk_orders_table` FOREIGN KEY (`table_id`) REFERENCES `restaurant_tables`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_waiter` FOREIGN KEY (`waiter_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Order Items (with updated_at)
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `menu_item_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(9,2) NOT NULL,
    `instructions` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'preparing', 'ready', 'served', 'completed') NOT NULL DEFAULT 'pending',
    `routed_to` ENUM('kitchen', 'bar') NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    INDEX `idx_order_items_order` (`order_id`),
    INDEX `idx_order_items_routed_status` (`routed_to`, `status`),
    INDEX `idx_order_items_created` (`created_at`),
    INDEX `idx_order_items_order_routed` (`order_id`, `routed_to`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_menu` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Order Status History
CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT DEFAULT NULL,
    `order_item_id` INT DEFAULT NULL,
    `from_status` VARCHAR(20) DEFAULT NULL,
    `to_status` VARCHAR(20) NOT NULL,
    `changed_by_user_id` INT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_osh_order_created` (`order_id`, `created_at`),
    INDEX `idx_osh_item_created` (`order_item_id`, `created_at`),
    INDEX `idx_osh_created` (`created_at`),
    CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_osh_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_osh_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `target_role` ENUM('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'all') NOT NULL DEFAULT 'all',
    `target_user_id` INT DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `body` TEXT NOT NULL,
    `type` ENUM('order_update', 'status_change', 'payment', 'system', 'alert') NOT NULL DEFAULT 'order_update',
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `sent_to_push` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_notifications_role_read_created` (`target_role`, `is_read`, `created_at`),
    INDEX `idx_notifications_user_read` (`target_user_id`, `is_read`),
    INDEX `idx_notifications_type` (`type`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Auth Tokens (with refresh_token, device_name, refresh_expires_at)
CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `refresh_token` VARCHAR(64) DEFAULT NULL,
    `device_name` VARCHAR(255) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `refresh_expires_at` DATETIME DEFAULT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_auth_tokens_user` (`user_id`),
    INDEX `idx_auth_tokens_expires` (`expires_at`),
    INDEX `idx_auth_tokens_refresh` (`refresh_token`),
    INDEX `idx_auth_tokens_user_revoked` (`user_id`, `revoked`),
    CONSTRAINT `fk_auth_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Print Jobs
CREATE TABLE IF NOT EXISTS `print_jobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_item_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `department` ENUM('kitchen', 'bar') NOT NULL,
    `printer` VARCHAR(50) NOT NULL DEFAULT 'default',
    `status` ENUM('pending', 'printing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` TEXT DEFAULT NULL,
    `printed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_print_jobs_status` (`status`),
    INDEX `idx_print_jobs_order` (`order_id`),
    INDEX `idx_print_jobs_department_status` (`department`, `status`),
    INDEX `idx_print_jobs_created` (`created_at`),
    CONSTRAINT `fk_print_jobs_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_print_jobs_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Settings
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `resource_type` VARCHAR(50) DEFAULT NULL,
    `resource_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`),
    INDEX `idx_audit_resource` (`resource_type`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Rate Limits
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(100) NOT NULL,
    `type` ENUM('api', 'login') NOT NULL DEFAULT 'api',
    `hits` INT NOT NULL DEFAULT 1,
    `window_start` DATETIME NOT NULL,
    `blocked_until` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_rate_type_id` (`type`, `identifier`, `window_start`),
    INDEX `idx_rate_blocked` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Schema Migrations Tracking
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL UNIQUE,
    `batch` INT NOT NULL DEFAULT 1,
    `executed_at` DATETIME NOT NULL,
    INDEX `idx_migrations` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Default Settings (no demo users)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('restaurant_name', 'Restaurant POS', NOW()),
('logo_url', '/assets/images/brainyte-icon.png', NOW()),
('vat_rate', '0.00', NOW()),
('currency', 'NGN', NOW()),
('timezone', 'Africa/Lagos', NOW()),
('printer_type', 'thermal', NOW()),
('footer_text', 'Powered by Brainyte', NOW()),
('direct_printing', '0', NOW());
