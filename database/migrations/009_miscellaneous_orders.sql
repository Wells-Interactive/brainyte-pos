-- Miscellaneous Orders: group orders across tables for waiters.
CREATE TABLE IF NOT EXISTS `miscellaneous_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `primary_table_id` INT NOT NULL,
    `waiter_id` INT NOT NULL,
    `status` ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_misc_primary_table` (`primary_table_id`),
    INDEX `idx_misc_waiter_created` (`waiter_id`, `created_at`),
    CONSTRAINT `fk_misc_primary_table` FOREIGN KEY (`primary_table_id`) REFERENCES `restaurant_tables`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_misc_waiter` FOREIGN KEY (`waiter_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `miscellaneous_order_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `miscellaneous_order_id` INT NOT NULL,
    `table_id` INT NOT NULL,
    `order_id` INT DEFAULT NULL,
    `assigned_at` DATETIME NOT NULL,
    `notes` TEXT DEFAULT NULL,
    INDEX `idx_misc_assign_misc` (`miscellaneous_order_id`),
    INDEX `idx_misc_assign_table` (`table_id`),
    CONSTRAINT `fk_misc_assign_misc` FOREIGN KEY (`miscellaneous_order_id`) REFERENCES `miscellaneous_orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_misc_assign_table` FOREIGN KEY (`table_id`) REFERENCES `restaurant_tables`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_misc_assign_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
