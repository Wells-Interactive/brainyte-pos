-- Add banned flag to customer_profiles for admin/owner ban functionality.
ALTER TABLE `customer_profiles`
    ADD COLUMN `banned` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `email_verified_at`,
    ADD INDEX `idx_customer_profiles_banned` (`banned`);
