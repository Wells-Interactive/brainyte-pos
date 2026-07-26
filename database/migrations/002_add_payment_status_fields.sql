-- Migration: 002_add_payment_status_fields.sql
-- Description: Add payment_status, total_amount, paid_amount to orders table
-- Generated: 2025-01-01

START TRANSACTION;

-- Add payment_status column if not exists
SET @has_payment_status = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status');
SET @sql1 = IF(@has_payment_status = 0, 
    'ALTER TABLE `orders` ADD COLUMN `payment_status` ENUM(\'unpaid\', \'partial\', \'paid\', \'refunded\', \'voided\') NOT NULL DEFAULT \'unpaid\' AFTER `payment_method`',
    'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Add total_amount column if not exists
SET @has_total_amount = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'total_amount');
SET @sql2 = IF(@has_total_amount = 0, 
    'ALTER TABLE `orders` ADD COLUMN `total_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Add paid_amount column if not exists
SET @has_paid_amount = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'paid_amount');
SET @sql3 = IF(@has_paid_amount = 0, 
    'ALTER TABLE `orders` ADD COLUMN `paid_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`',
    'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Add index on payment_status
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_payment_status');
SET @sql4 = IF(@has_idx = 0, 
    'ALTER TABLE `orders` ADD INDEX `idx_orders_payment_status` (`payment_status`)',
    'SELECT 1');
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- Update existing orders that were 'completed' to have payment_status = 'paid'
UPDATE `orders` SET `payment_status` = 'paid' WHERE `status` = 'completed' AND `payment_status` = 'unpaid';

COMMIT;

