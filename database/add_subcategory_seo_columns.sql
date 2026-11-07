-- Subcategory SEO uses the same product_category table as categories.
-- A subcategory is a product_category row where parent_id is not null.
-- Run this (or add_category_seo_columns.sql) once; IF NOT EXISTS makes it safe to run both.
ALTER TABLE product_category
  ADD COLUMN IF NOT EXISTS meta_title VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS meta_description VARCHAR(512) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS meta_keywords VARCHAR(512) NULL DEFAULT NULL;
