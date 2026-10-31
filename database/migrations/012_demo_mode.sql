-- Adds the flag used by the dedicated, read-only demo owner account.
ALTER TABLE `users`
    ADD COLUMN `is_demo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`,
    ADD INDEX `idx_users_is_demo` (`is_demo`);

INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('demo_mode_enabled', '0', NOW())
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
