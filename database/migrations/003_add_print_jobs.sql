-- Migration: 003_add_print_jobs.sql
-- Description: Create print_jobs table for tracking print queue
-- Generated: 2025-01-01

START TRANSACTION;

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

COMMIT;

