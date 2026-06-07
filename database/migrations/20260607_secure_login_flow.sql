-- Secure Login Flow migration.
-- Run against the Akaunting_importer database. Safe to re-run on MySQL/MariaDB
-- versions that support IF NOT EXISTS for ALTER TABLE ADD COLUMN/INDEX.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_approved` tinyint(1) NOT NULL DEFAULT 0 AFTER `email`,
  ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `is_approved`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_is_approved` (`is_approved`),
  ADD INDEX IF NOT EXISTS `idx_users_approved_by` (`approved_by`);

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `blocked_reason` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_email_created` (`email`, `created_at`),
  KEY `idx_login_attempts_ip_created` (`ip_address`, `created_at`),
  KEY `idx_login_attempts_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the self-referencing approval foreign key manually if your MySQL/MariaDB
-- version does not support guarded ADD CONSTRAINT syntax.
-- ALTER TABLE `users`
--   ADD CONSTRAINT `users_approved_by_fk`
--   FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

DELETE at
FROM `auth_tokens` at
JOIN `users` u ON u.`user_id` = at.`user_id`
WHERE u.`is_approved` = 0;
