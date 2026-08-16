-- Home delivery: customer registration, verified email and rider tracking.

ALTER TABLE `users` MODIFY `role` ENUM('waiter','kitchen','bar','manager','supervisor','admin','owner','customer','rider') NOT NULL;

CREATE TABLE IF NOT EXISTS `customer_profiles` (
    `user_id` INT PRIMARY KEY,
    `phone_number` VARCHAR(30) NOT NULL,
    `email_verified_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    CONSTRAINT `fk_customer_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verification_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_evc_user_expires` (`user_id`, `expires_at`),
    CONSTRAINT `fk_evc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `rider_id` INT DEFAULT NULL,
    `items_json` JSON NOT NULL,
    `total_amount` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    `delivery_address` TEXT NOT NULL,
    `phone_number` VARCHAR(30) NOT NULL,
    `alternate_phone_number` VARCHAR(30) DEFAULT NULL,
    `special_requests` TEXT DEFAULT NULL,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `status` ENUM('requested','accepted','preparing','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'requested',
    `payment_method` ENUM('cash','pos','transfer','pending') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_delivery_customer_created` (`customer_id`, `created_at`),
    INDEX `idx_delivery_rider_status` (`rider_id`, `status`),
    INDEX `idx_delivery_status_created` (`status`, `created_at`),
    CONSTRAINT `fk_delivery_customer` FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_delivery_rider` FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_tracking` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `delivery_order_id` INT NOT NULL,
    `rider_id` INT NOT NULL,
    `latitude` DECIMAL(10,7) NOT NULL,
    `longitude` DECIMAL(10,7) NOT NULL,
    `accuracy_meters` DECIMAL(8,2) DEFAULT NULL,
    `recorded_at` DATETIME NOT NULL,
    INDEX `idx_tracking_delivery_recorded` (`delivery_order_id`, `recorded_at`),
    CONSTRAINT `fk_tracking_delivery` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tracking_rider` FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('home_delivery_enabled', '0', NOW());
