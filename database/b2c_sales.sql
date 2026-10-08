-- B2C sales: product flag + dedicated order tables (separate from bulk enquiries & main orders)

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `is_b2c_sale` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = available on B2C sales storefront' AFTER `featured`;

CREATE TABLE IF NOT EXISTS `b2c_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(32) NOT NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `customer_email` VARCHAR(255) NOT NULL,
  `customer_phone` VARCHAR(20) NOT NULL,
  `address_line1` VARCHAR(500) NOT NULL,
  `address_line2` VARCHAR(500) DEFAULT NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `pincode` VARCHAR(20) NOT NULL,
  `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `pay_status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `pay_gateway_name` VARCHAR(50) NOT NULL DEFAULT 'razorpay',
  `razorpay_order_id` VARCHAR(100) DEFAULT NULL,
  `razorpay_payment_id` VARCHAR(100) DEFAULT NULL,
  `razorpay_signature` VARCHAR(255) DEFAULT NULL,
  `txn_id` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_b2c_orders_order_no` (`order_no`),
  KEY `idx_b2c_orders_pay_status` (`pay_status`),
  KEY `idx_b2c_orders_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `b2c_order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `b2c_order_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `variation_id` INT UNSIGNED DEFAULT NULL,
  `product_name` VARCHAR(500) NOT NULL,
  `sku` VARCHAR(100) DEFAULT NULL,
  `qty` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_b2c_order_items_order` (`b2c_order_id`),
  KEY `idx_b2c_order_items_product` (`product_id`),
  CONSTRAINT `fk_b2c_order_items_order` FOREIGN KEY (`b2c_order_id`) REFERENCES `b2c_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
