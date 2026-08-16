-- Migration: 002_add_payment_status_fields.sql
-- Description: Add payment fields to orders

ALTER TABLE `orders`
    ADD COLUMN `payment_status`
        ENUM('unpaid', 'partial', 'paid', 'refunded', 'voided')
        NOT NULL DEFAULT 'unpaid'
        AFTER `payment_method`;

ALTER TABLE `orders`
    ADD COLUMN `total_amount`
        DECIMAL(9,2)
        NOT NULL DEFAULT 0.00
        AFTER `payment_status`;

ALTER TABLE `orders`
    ADD COLUMN `paid_amount`
        DECIMAL(9,2)
        NOT NULL DEFAULT 0.00
        AFTER `total_amount`;

ALTER TABLE `orders`
    ADD INDEX `idx_orders_payment_status` (`payment_status`);

UPDATE `orders`
SET `payment_status` = 'paid'
WHERE `status` = 'completed'
  AND `payment_status` = 'unpaid';
