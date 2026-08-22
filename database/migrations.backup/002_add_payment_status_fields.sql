-- Migration: 002_add_payment_status_fields.sql
-- Description: Add payment fields to orders (idempotent)

-- Add payment_status column if it does not exist
SET @dbname = DATABASE();
SET @stmt = (
  SELECT IF(
    (
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'payment_status'
    ) > 0,
    'SELECT 1',
    'ALTER TABLE `orders` ADD COLUMN `payment_status` ENUM(''unpaid'',''partial'',''paid'',''refunded'',''voided'') NOT NULL DEFAULT ''unpaid'' AFTER `payment_method`'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add total_amount column if it does not exist
SET @stmt = (
  SELECT IF(
    (
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'total_amount'
    ) > 0,
    'SELECT 1',
    'ALTER TABLE `orders` ADD COLUMN `total_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add paid_amount column if it does not exist
SET @stmt = (
  SELECT IF(
    (
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @dbname
        AND TABLE_NAME = 'orders'
        AND COLUMN_NAME = 'paid_amount'
    ) > 0,
    'SELECT 1',
    'ALTER TABLE `orders` ADD COLUMN `paid_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`'
  )
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if it does not exist
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_payment_status'
);
SET @stmt = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `orders` ADD INDEX `idx_orders_payment_status` (`payment_status`)');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill payment_status for completed orders that are still unpaid
UPDATE `orders`
SET `payment_status` = 'paid'
WHERE `status` = 'completed'
  AND `payment_status` = 'unpaid';
