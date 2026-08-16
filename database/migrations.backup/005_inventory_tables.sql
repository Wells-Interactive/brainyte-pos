-- Migration: 005_inventory_tables.sql
-- Description: Create inventory management tables (items + movements)
-- Generated: 2025-01-15


-- =============================================================================
-- Inventory Items
-- Tracks current stock levels for each menu item.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `menu_item_id` INT NOT NULL,
    `current_stock` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `min_stock_level` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `unit` VARCHAR(30) NOT NULL DEFAULT 'pieces',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_inventory_menu_item` (`menu_item_id`),
    INDEX `idx_inventory_stock_level` (`current_stock`),
    INDEX `idx_inventory_min_stock` (`min_stock_level`),
    CONSTRAINT `fk_inventory_menu_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- =============================================================================
-- Inventory Movements
-- Records every stock change for complete audit trail.
-- type: stock_in (purchase/restock), stock_out (sale/deduction), adjustment
-- =============================================================================
CREATE TABLE IF NOT EXISTS `inventory_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inventory_item_id` INT NOT NULL,
    `type` ENUM('stock_in', 'stock_out', 'adjustment') NOT NULL,
    `quantity` DECIMAL(9,2) NOT NULL,
    `previous_qty` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `new_qty` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. order, purchase, adjustment',
    `reference_id` INT DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `performed_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_im_inventory_item` (`inventory_item_id`),
    INDEX `idx_im_type` (`type`),
    INDEX `idx_im_created` (`created_at`),
    INDEX `idx_im_reference` (`reference_type`, `reference_id`),
    CONSTRAINT `fk_im_inventory_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_im_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Insert default inventory records for existing menu items (with zero stock)
INSERT INTO `inventory_items` (`menu_item_id`, `current_stock`, `min_stock_level`, `unit`, `created_at`, `updated_at`)
SELECT `id`, 0, 10, 'pieces', NOW(), NOW()
FROM `menu_items`
WHERE `id` NOT IN (SELECT `menu_item_id` FROM `inventory_items`);


