-- Run this on the same database as khaitan_api (e.g. u896104554_khaitan_web).
-- Via phpMyAdmin: select the database → SQL → paste → Go.
-- Or CLI: mysql -u USER -p DATABASE_NAME < database/contact_enquiries.sql

CREATE TABLE IF NOT EXISTS `contact_enquiries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(40) NOT NULL,
  `message` TEXT NULL DEFAULT NULL,
  `address` VARCHAR(500) NULL DEFAULT NULL,
  `country` VARCHAR(120) NULL DEFAULT NULL,
  `state` VARCHAR(120) NULL DEFAULT NULL,
  `city` VARCHAR(120) NULL DEFAULT NULL,
  `pin` VARCHAR(30) NULL DEFAULT NULL,
  `form_source` VARCHAR(120) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(500) NULL DEFAULT NULL,
  `email_sent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_enquiries_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
