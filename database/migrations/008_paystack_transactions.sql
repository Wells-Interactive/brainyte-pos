-- Paystack payment tracking for delivery orders.
CREATE TABLE IF NOT EXISTS `paystack_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `delivery_order_id` INT NOT NULL,
    `reference` VARCHAR(100) NOT NULL UNIQUE,
    `amount` DECIMAL(9,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'NGN',
    `status` ENUM('initialized','pending','success','failed','abandoned') NOT NULL DEFAULT 'initialized',
    `customer_email` VARCHAR(120) NOT NULL,
    `callback_url` VARCHAR(500) DEFAULT NULL,
    `gateway_response` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_paystack_delivery` (`delivery_order_id`),
    INDEX `idx_paystack_reference` (`reference`),
    INDEX `idx_paystack_customer_email` (`customer_email`),
    CONSTRAINT `fk_paystack_delivery_order` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
