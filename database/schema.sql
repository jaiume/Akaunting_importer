-- Akaunting Importer Database Schema

CREATE DATABASE IF NOT EXISTS `Akaunting_importer` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `Akaunting_importer`;

-- Users table (passwordless - email only)
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login tokens table (one-time use, emailed to users for login)
CREATE TABLE IF NOT EXISTS `login_tokens` (
  `login_token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiry` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`login_token_id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `expiry` (`expiry`),
  KEY `used` (`used`),
  CONSTRAINT `login_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authentication tokens table (session tokens for logged-in users)
-- Uses rolling expiry: token is valid if last_accessed is within token_expiry window (config.ini)
-- last_accessed is updated on every successful token verification
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `last_accessed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  KEY `user_id` (`user_id`),
  KEY `last_accessed` (`last_accessed`),
  CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entities table (Real world entities)
CREATE TABLE IF NOT EXISTS `entities` (
  `entity_id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `entity_name` (`entity_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akaunting installations table (one-to-one with entities)
-- Each entity has exactly one Akaunting installation
CREATE TABLE IF NOT EXISTS `akaunting_installations` (
  `installation_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `entity_id` int(11) DEFAULT NULL COMMENT 'The entity this installation belongs to (1:1 relationship)',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `base_url` varchar(500) NOT NULL,
  `api_email` varchar(255) NOT NULL,
  `api_password` varchar(500) NOT NULL COMMENT 'Encrypted password for API authentication',
  `company_id` int(11) DEFAULT 1 COMMENT 'Akaunting company ID for X-Company header',
  `is_active` tinyint(1) DEFAULT 1,
  `last_sync` datetime DEFAULT NULL COMMENT 'Last successful API sync/test',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`installation_id`),
  KEY `user_id` (`user_id`),
  UNIQUE KEY `unique_entity` (`entity_id`),
  KEY `is_active` (`is_active`),
  CONSTRAINT `akaunting_installations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `akaunting_installations_ibfk_2` FOREIGN KEY (`entity_id`) REFERENCES `entities` (`entity_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts table (Real world accounts)
-- account_type determines how transaction values are interpreted:
--   bank: negative = expense, positive = income
--   credit_card: positive = expense, negative = income (payments/refunds)
-- akaunting_account_id/name link this account to its corresponding Akaunting account
CREATE TABLE IF NOT EXISTS `accounts` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `account_type` enum('bank','credit_card') NOT NULL DEFAULT 'bank',
  `currency` varchar(10) DEFAULT 'USD',
  `is_active` tinyint(1) DEFAULT 1,
  `akaunting_account_id` int(11) DEFAULT NULL COMMENT 'Linked Akaunting account ID',
  `akaunting_account_name` varchar(255) DEFAULT NULL COMMENT 'Cached Akaunting account name',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  KEY `entity_id` (`entity_id`),
  UNIQUE KEY `entity_account` (`entity_id`, `account_name`),
  CONSTRAINT `accounts_entity_fk` FOREIGN KEY (`entity_id`) REFERENCES `entities` (`entity_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import batches table
CREATE TABLE IF NOT EXISTS `import_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_name` varchar(255) NOT NULL,
  `account_id` int(11) NOT NULL,
  `batch_import_type` enum('PDF','CSV') NOT NULL,
  `import_processor` varchar(100) DEFAULT NULL COMMENT 'e.g., rbl_credit_card_csv, rbl_credit_card_pdf, rbl_bank_csv, rbl_bank_pdf',
  `batch_import_filename` varchar(500) NOT NULL,
  `batch_import_datetime` datetime NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'User who created the import',
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `total_transactions` int(11) DEFAULT 0,
  `processed_transactions` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`batch_id`),
  KEY `account_id` (`account_id`),
  KEY `status` (`status`),
  KEY `user_id` (`user_id`),
  KEY `is_archived` (`is_archived`),
  CONSTRAINT `import_batches_account_fk` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE,
  CONSTRAINT `import_batches_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import transactions table
CREATE TABLE IF NOT EXISTS `import_transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `bank_ref` varchar(255) DEFAULT NULL COMMENT 'Bank reference number/ID',
  `description` text DEFAULT NULL,
  `transaction_currency` varchar(10) NOT NULL DEFAULT 'TTD',
  `transaction_amount` decimal(15,2) NOT NULL,
  `transaction_type` enum('debit','credit') DEFAULT NULL,
  `status` enum('pending','processed','error') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `matched_akaunting_id` int(11) DEFAULT NULL COMMENT 'Matched Akaunting transaction ID',
  `matched_akaunting_number` varchar(50) DEFAULT NULL COMMENT 'Matched Akaunting transaction number',
  `matched_akaunting_date` date DEFAULT NULL COMMENT 'Matched transaction date from Akaunting',
  `matched_akaunting_amount` decimal(15,2) DEFAULT NULL COMMENT 'Matched transaction amount from Akaunting',
  `matched_akaunting_description` text DEFAULT NULL COMMENT 'Matched transaction description from Akaunting',
  `match_confidence` enum('high','medium','low') DEFAULT NULL COMMENT 'Confidence level of match',
  `pushed_akaunting_transaction_number` varchar(50) DEFAULT NULL COMMENT 'Transaction number when pushed (e.g. IMP-TRA-443)',
  `pushed_at` timestamp NULL DEFAULT NULL COMMENT 'When transaction was pushed to Akaunting',
  `replicated_akaunting_id` int(11) DEFAULT NULL COMMENT 'Akaunting transaction ID in target entity',
  `replicated_akaunting_transaction_number` varchar(50) DEFAULT NULL COMMENT 'Transaction number in target entity',
  `replicated_to_entity_id` int(11) DEFAULT NULL COMMENT 'Target entity ID for replication',
  `replicated_at` timestamp NULL DEFAULT NULL COMMENT 'When transaction was replicated',
  PRIMARY KEY (`transaction_id`),
  KEY `batch_id` (`batch_id`),
  KEY `transaction_date` (`transaction_date`),
  KEY `bank_ref` (`bank_ref`),
  KEY `status` (`status`),
  KEY `matched_akaunting_id` (`matched_akaunting_id`),
  CONSTRAINT `import_transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `import_batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction to Akaunting transfers table
-- Tracks when transactions are synced to Akaunting
CREATE TABLE IF NOT EXISTS `transaction_akaunting_transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `akaunting_installation_id` int(11) NOT NULL,
  `transfer_amount` decimal(15,2) DEFAULT NULL COMMENT 'Amount to transfer (if splitting transaction)',
  `transfer_currency` varchar(10) DEFAULT NULL COMMENT 'Currency for this transfer',
  `akaunting_transaction_id` int(11) DEFAULT NULL COMMENT 'Linked Akaunting transaction ID after import',
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `transferred_at` datetime DEFAULT NULL COMMENT 'When the transfer was completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transfer_id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `akaunting_installation_id` (`akaunting_installation_id`),
  KEY `status` (`status`),
  UNIQUE KEY `transaction_installation` (`transaction_id`, `akaunting_installation_id`),
  CONSTRAINT `transaction_akaunting_transfers_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `import_transactions` (`transaction_id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_akaunting_transfers_ibfk_2` FOREIGN KEY (`akaunting_installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akaunting contacts/vendors cache
-- Caches contacts from Akaunting to avoid slow API calls
CREATE TABLE IF NOT EXISTS `akaunting_contacts` (
  `contact_id` int(11) NOT NULL AUTO_INCREMENT,
  `installation_id` int(11) NOT NULL,
  `akaunting_contact_id` int(11) NOT NULL COMMENT 'Contact ID in Akaunting',
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `type` enum('customer','vendor') DEFAULT 'vendor',
  `enabled` tinyint(1) DEFAULT 1,
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`),
  UNIQUE KEY `installation_akaunting_contact` (`installation_id`, `akaunting_contact_id`),
  KEY `installation_id` (`installation_id`),
  KEY `name` (`name`),
  KEY `type` (`type`),
  CONSTRAINT `akaunting_contacts_ibfk_1` FOREIGN KEY (`installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akaunting categories cache
-- Caches categories from Akaunting to avoid slow API calls
CREATE TABLE IF NOT EXISTS `akaunting_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `installation_id` int(11) NOT NULL,
  `akaunting_category_id` int(11) NOT NULL COMMENT 'Category ID in Akaunting',
  `name` varchar(255) NOT NULL,
  `type` varchar(50) DEFAULT NULL COMMENT 'income, expense, item, other',
  `color` varchar(20) DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `installation_akaunting_category` (`installation_id`, `akaunting_category_id`),
  KEY `installation_id` (`installation_id`),
  KEY `name` (`name`),
  KEY `type` (`type`),
  CONSTRAINT `akaunting_categories_ibfk_1` FOREIGN KEY (`installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akaunting payment methods cache
-- Caches payment methods from Akaunting settings
CREATE TABLE IF NOT EXISTS `akaunting_payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `installation_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL COMMENT 'Payment method code (e.g., cash, bank_transfer)',
  `name` varchar(255) NOT NULL COMMENT 'Display name',
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `installation_code` (`installation_id`, `code`),
  KEY `installation_id` (`installation_id`),
  CONSTRAINT `akaunting_payment_methods_ibfk_1` FOREIGN KEY (`installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction mappings table
-- Maps imported transaction descriptions to vendor, category, and payment method
-- Used for auto-suggesting values when pushing transactions
CREATE TABLE IF NOT EXISTS `transaction_mappings` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `installation_id` int(11) NOT NULL,
  `description_pattern` varchar(255) NOT NULL COMMENT 'Pattern from imported transaction description',
  `transaction_type` enum('income','expense','transfer') DEFAULT NULL COMMENT 'Transaction type (income, expense, or transfer)',
  `akaunting_contact_id` int(11) DEFAULT NULL COMMENT 'Mapped Akaunting contact ID',
  `contact_name` varchar(255) DEFAULT NULL COMMENT 'Cached contact name for display',
  `akaunting_category_id` int(11) DEFAULT NULL COMMENT 'Mapped Akaunting category ID',
  `category_name` varchar(255) DEFAULT NULL COMMENT 'Cached category name for display',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'Payment method code',
  `transfer_to_account_id` int(11) DEFAULT NULL COMMENT 'For transfers: the destination Akaunting account ID',
  `usage_count` int(11) DEFAULT 1 COMMENT 'How many times this mapping has been used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `installation_pattern` (`installation_id`, `description_pattern`),
  KEY `installation_id` (`installation_id`),
  KEY `description_pattern` (`description_pattern`),
  KEY `usage_count` (`usage_count`),
  CONSTRAINT `transaction_mappings_ibfk_1` FOREIGN KEY (`installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy vendor mappings table (deprecated - use transaction_mappings instead)
-- Maps imported transaction descriptions to Akaunting contacts
-- Used for auto-suggesting vendors when pushing transactions
CREATE TABLE IF NOT EXISTS `vendor_mappings` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `installation_id` int(11) NOT NULL,
  `description_pattern` varchar(255) NOT NULL COMMENT 'Pattern from imported transaction description',
  `akaunting_contact_id` int(11) NOT NULL COMMENT 'Mapped Akaunting contact ID',
  `contact_name` varchar(255) NOT NULL COMMENT 'Cached contact name for display',
  `usage_count` int(11) DEFAULT 1 COMMENT 'How many times this mapping has been used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `installation_pattern` (`installation_id`, `description_pattern`),
  KEY `installation_id` (`installation_id`),
  KEY `description_pattern` (`description_pattern`),
  KEY `usage_count` (`usage_count`),
  CONSTRAINT `vendor_mappings_ibfk_1` FOREIGN KEY (`installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akaunting orphan transactions table
-- Stores transactions from Akaunting that exist within the batch date range
-- but have no matching imported transaction (orphans)
CREATE TABLE IF NOT EXISTS `akaunting_orphan_transactions` (
  `orphan_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL COMMENT 'The batch this orphan was detected for',
  `akaunting_id` int(11) NOT NULL COMMENT 'Transaction ID in Akaunting',
  `akaunting_number` varchar(50) DEFAULT NULL COMMENT 'Transaction number from Akaunting',
  `transaction_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `type` varchar(50) DEFAULT NULL COMMENT 'income, expense, income-transfer, expense-transfer',
  `description` text DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL COMMENT 'Vendor/Customer name',
  `category` varchar(255) DEFAULT NULL COMMENT 'Category name',
  `currency_code` varchar(10) DEFAULT 'TTD',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`orphan_id`),
  KEY `batch_id` (`batch_id`),
  KEY `akaunting_id` (`akaunting_id`),
  KEY `transaction_date` (`transaction_date`),
  UNIQUE KEY `batch_akaunting` (`batch_id`, `akaunting_id`),
  CONSTRAINT `akaunting_orphan_transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `import_batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cross-entity mappings table
-- Maps vendor/category selections from one entity to another for cross-entity replication
-- When a transaction is replicated to another entity, the mapping is saved for pre-selection
CREATE TABLE IF NOT EXISTS `cross_entity_mappings` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `source_installation_id` int(11) NOT NULL COMMENT 'Source Akaunting installation ID',
  `source_vendor_id` int(11) DEFAULT NULL COMMENT 'Source vendor/contact ID',
  `source_category_id` int(11) DEFAULT NULL COMMENT 'Source category ID',
  `target_installation_id` int(11) NOT NULL COMMENT 'Target Akaunting installation ID',
  `target_vendor_id` int(11) DEFAULT NULL COMMENT 'Target vendor/contact ID',
  `target_category_id` int(11) DEFAULT NULL COMMENT 'Target category ID',
  `target_account_id` int(11) DEFAULT NULL COMMENT 'Target Akaunting account ID',
  `target_payment_method` varchar(100) DEFAULT NULL COMMENT 'Target payment method code',
  `usage_count` int(11) DEFAULT 1 COMMENT 'How many times this mapping has been used',
  `last_used_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `unique_mapping` (`source_installation_id`, `source_vendor_id`, `source_category_id`, `target_installation_id`),
  KEY `source_installation_id` (`source_installation_id`),
  KEY `target_installation_id` (`target_installation_id`),
  KEY `usage_count` (`usage_count`),
  CONSTRAINT `cross_entity_mappings_source_fk` FOREIGN KEY (`source_installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE,
  CONSTRAINT `cross_entity_mappings_target_fk` FOREIGN KEY (`target_installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replication transaction mappings table
-- Maps transaction description patterns to target installation settings for predictive replication
-- Similar to transaction_mappings but for cross-entity replication based on description
CREATE TABLE IF NOT EXISTS `replication_transaction_mappings` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `source_installation_id` int(11) NOT NULL COMMENT 'Source installation where transaction originates',
  `target_installation_id` int(11) NOT NULL COMMENT 'Target installation for replication',
  `description_pattern` varchar(255) NOT NULL COMMENT 'Pattern from transaction description',
  `transaction_type` enum('income','expense') DEFAULT NULL COMMENT 'Transaction type (income or expense)',
  `target_contact_id` int(11) DEFAULT NULL COMMENT 'Target vendor/customer ID',
  `target_contact_name` varchar(255) DEFAULT NULL COMMENT 'Cached contact name',
  `target_category_id` int(11) DEFAULT NULL COMMENT 'Target category ID',
  `target_category_name` varchar(255) DEFAULT NULL COMMENT 'Cached category name',
  `target_account_id` int(11) DEFAULT NULL COMMENT 'Target Akaunting account ID',
  `target_payment_method` varchar(100) DEFAULT NULL COMMENT 'Target payment method code',
  `usage_count` int(11) DEFAULT 1 COMMENT 'How many times this mapping has been used',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `unique_pattern_mapping` (`source_installation_id`, `target_installation_id`, `description_pattern`),
  KEY `source_installation_id` (`source_installation_id`),
  KEY `target_installation_id` (`target_installation_id`),
  KEY `description_pattern` (`description_pattern`),
  KEY `usage_count` (`usage_count`),
  CONSTRAINT `replication_mappings_source_fk` FOREIGN KEY (`source_installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE,
  CONSTRAINT `replication_mappings_target_fk` FOREIGN KEY (`target_installation_id`) REFERENCES `akaunting_installations` (`installation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
