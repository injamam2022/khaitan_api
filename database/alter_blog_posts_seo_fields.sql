-- Add SEO / content helper columns if blog_posts already exists
-- Safe to re-run only if you check columns first; prefer opening Blog in admin (auto-adds columns).

ALTER TABLE blog_posts
  ADD COLUMN IF NOT EXISTS image_alt VARCHAR(255) NULL AFTER image,
  ADD COLUMN IF NOT EXISTS canonical_url VARCHAR(500) NULL AFTER seo_description,
  ADD COLUMN IF NOT EXISTS internal_links TEXT NULL AFTER canonical_url;
