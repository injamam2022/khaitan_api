-- ============================================================
-- Order Table Migration — Production SQL
-- Run on: u896104554_khaitan_web (Hostinger production DB)
-- Date: 2026-03-29
-- Purpose: Add missing columns for EasyEcom integration
-- ============================================================
-- NOTE: Run each statement ONE BY ONE in phpMyAdmin.
-- If any gives "#1060 Duplicate column name", skip it and move to the next.
-- ============================================================

-- 1. easyecom_sync_status — tracks sync state (SYNCED, CANCELLED, etc.)
ALTER TABLE `orders` ADD COLUMN `easyecom_sync_status` VARCHAR(50) DEFAULT NULL AFTER `easyecom_order_id`;

-- 2. tracking_url — carrier tracking URL from EasyEcom/Delhivery
ALTER TABLE `orders` ADD COLUMN `tracking_url` VARCHAR(512) DEFAULT NULL AFTER `label_url`;

-- 3. total_amount — total order amount for carrier payload
ALTER TABLE `orders` ADD COLUMN `total_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `paid_amount`;

-- 4. remarks — order remarks for EasyEcom payload
ALTER TABLE `orders` ADD COLUMN `remarks` TEXT DEFAULT NULL AFTER `refund_status`;

-- 5. completed_date — order completion date tracking
ALTER TABLE `orders` ADD COLUMN `completed_date` DATE DEFAULT NULL AFTER `cancelled_at`;


-- ============================================================
-- BACKFILL existing orders
-- ============================================================

-- Populate state from address for any orders missing it
UPDATE `orders` o
INNER JOIN `user_saved_address` a ON o.address_id = a.id
SET o.state = a.state
WHERE (o.state IS NULL OR o.state = '')
  AND a.state IS NOT NULL AND a.state != '';

-- Populate total_amount from paid_amount
UPDATE `orders`
SET total_amount = paid_amount
WHERE total_amount = 0 AND paid_amount > 0;
