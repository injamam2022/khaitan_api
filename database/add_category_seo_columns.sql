-- Categories and subcategories both live in product_category.
-- Subcategories are rows with parent_id set (e.g. Storage Geysers under Geysers).
-- These columns are used for /pages/{category} and /collections/{subcategory} meta tags.
ALTER TABLE product_category
  ADD COLUMN IF NOT EXISTS meta_title VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS meta_description VARCHAR(512) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS meta_keywords VARCHAR(512) NULL DEFAULT NULL;
