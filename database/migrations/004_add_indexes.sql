-- Migration: 004_add_indexes.sql
-- Description: Add all recommended performance indexes
-- Generated: 2025-01-01

START TRANSACTION;

-- Orders indexes (skip if already exist from initial schema)
SET @has_idx1 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_table_status');
SET @sql1 = IF(@has_idx1 = 0, 
    'ALTER TABLE `orders` ADD INDEX `idx_orders_table_status` (`table_id`, `status`)', 'SELECT 1');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @has_idx2 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_waiter_created');
SET @sql2 = IF(@has_idx2 = 0, 
    'ALTER TABLE `orders` ADD INDEX `idx_orders_waiter_created` (`waiter_id`, `created_at`)', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @has_idx3 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_status_created');
SET @sql3 = IF(@has_idx3 = 0, 
    'ALTER TABLE `orders` ADD INDEX `idx_orders_status_created` (`status`, `created_at`)', 'SELECT 1');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

SET @has_idx4 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_created');
SET @sql4 = IF(@has_idx4 = 0, 
    'ALTER TABLE `orders` ADD INDEX `idx_orders_created` (`created_at`)', 'SELECT 1');
PREPARE stmt4 FROM @sql4; EXECUTE stmt4; DEALLOCATE PREPARE stmt4;

-- Order items indexes
SET @has_idx5 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_order');
SET @sql5 = IF(@has_idx5 = 0, 
    'ALTER TABLE `order_items` ADD INDEX `idx_order_items_order` (`order_id`)', 'SELECT 1');
PREPARE stmt5 FROM @sql5; EXECUTE stmt5; DEALLOCATE PREPARE stmt5;

SET @has_idx6 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_routed_status');
SET @sql6 = IF(@has_idx6 = 0, 
    'ALTER TABLE `order_items` ADD INDEX `idx_order_items_routed_status` (`routed_to`, `status`)', 'SELECT 1');
PREPARE stmt6 FROM @sql6; EXECUTE stmt6; DEALLOCATE PREPARE stmt6;

SET @has_idx7 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_created');
SET @sql7 = IF(@has_idx7 = 0, 
    'ALTER TABLE `order_items` ADD INDEX `idx_order_items_created` (`created_at`)', 'SELECT 1');
PREPARE stmt7 FROM @sql7; EXECUTE stmt7; DEALLOCATE PREPARE stmt7;

SET @has_idx8 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_order_routed');
SET @sql8 = IF(@has_idx8 = 0, 
    'ALTER TABLE `order_items` ADD INDEX `idx_order_items_order_routed` (`order_id`, `routed_to`)', 'SELECT 1');
PREPARE stmt8 FROM @sql8; EXECUTE stmt8; DEALLOCATE PREPARE stmt8;

-- Notifications indexes
SET @has_idx9 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_role_read_created');
SET @sql9 = IF(@has_idx9 = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_notifications_role_read_created` (`target_role`, `is_read`, `created_at`)', 'SELECT 1');
PREPARE stmt9 FROM @sql9; EXECUTE stmt9; DEALLOCATE PREPARE stmt9;

SET @has_idx10 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_user_read');
SET @sql10 = IF(@has_idx10 = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_notifications_user_read` (`target_user_id`, `is_read`)', 'SELECT 1');
PREPARE stmt10 FROM @sql10; EXECUTE stmt10; DEALLOCATE PREPARE stmt10;

SET @has_idx11 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_type');
SET @sql11 = IF(@has_idx11 = 0, 
    'ALTER TABLE `notifications` ADD INDEX `idx_notifications_type` (`type`)', 'SELECT 1');
PREPARE stmt11 FROM @sql11; EXECUTE stmt11; DEALLOCATE PREPARE stmt11;

-- Auth tokens indexes
SET @has_idx12 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_tokens' AND INDEX_NAME = 'idx_auth_tokens_user');
SET @sql12 = IF(@has_idx12 = 0, 
    'ALTER TABLE `auth_tokens` ADD INDEX `idx_auth_tokens_user` (`user_id`)', 'SELECT 1');
PREPARE stmt12 FROM @sql12; EXECUTE stmt12; DEALLOCATE PREPARE stmt12;

SET @has_idx13 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_tokens' AND INDEX_NAME = 'idx_auth_tokens_expires');
SET @sql13 = IF(@has_idx13 = 0, 
    'ALTER TABLE `auth_tokens` ADD INDEX `idx_auth_tokens_expires` (`expires_at`)', 'SELECT 1');
PREPARE stmt13 FROM @sql13; EXECUTE stmt13; DEALLOCATE PREPARE stmt13;

SET @has_idx14 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_tokens' AND INDEX_NAME = 'idx_auth_tokens_user_revoked');
SET @sql14 = IF(@has_idx14 = 0, 
    'ALTER TABLE `auth_tokens` ADD INDEX `idx_auth_tokens_user_revoked` (`user_id`, `revoked`)', 'SELECT 1');
PREPARE stmt14 FROM @sql14; EXECUTE stmt14; DEALLOCATE PREPARE stmt14;

-- Order status history indexes
SET @has_idx15 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_history' AND INDEX_NAME = 'idx_osh_order_created');
SET @sql15 = IF(@has_idx15 = 0, 
    'ALTER TABLE `order_status_history` ADD INDEX `idx_osh_order_created` (`order_id`, `created_at`)', 'SELECT 1');
PREPARE stmt15 FROM @sql15; EXECUTE stmt15; DEALLOCATE PREPARE stmt15;

SET @has_idx16 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_history' AND INDEX_NAME = 'idx_osh_item_created');
SET @sql16 = IF(@has_idx16 = 0, 
    'ALTER TABLE `order_status_history` ADD INDEX `idx_osh_item_created` (`order_item_id`, `created_at`)', 'SELECT 1');
PREPARE stmt16 FROM @sql16; EXECUTE stmt16; DEALLOCATE PREPARE stmt16;

-- Users role index
SET @has_idx17 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role');
SET @sql17 = IF(@has_idx17 = 0, 
    'ALTER TABLE `users` ADD INDEX `idx_users_role` (`role`)', 'SELECT 1');
PREPARE stmt17 FROM @sql17; EXECUTE stmt17; DEALLOCATE PREPARE stmt17;

-- Menu items category_available index
SET @has_idx18 = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND INDEX_NAME = 'idx_menu_items_category_available');
SET @sql18 = IF(@has_idx18 = 0, 
    'ALTER TABLE `menu_items` ADD INDEX `idx_menu_items_category_available` (`category`, `available`)', 'SELECT 1');
PREPARE stmt18 FROM @sql18; EXECUTE stmt18; DEALLOCATE PREPARE stmt18;

COMMIT;

