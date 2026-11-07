-- Blog posts for admin Blog module + storefront listings
-- Run in phpMyAdmin / MySQL on the production database.

CREATE TABLE IF NOT EXISTS blog_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  category VARCHAR(120) NOT NULL DEFAULT '',
  subcategory VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NULL,
  image VARCHAR(500) NULL,
  image_alt VARCHAR(255) NULL,
  link VARCHAR(500) NULL,
  content LONGTEXT NULL,
  seo_title VARCHAR(180) NULL,
  seo_description VARCHAR(320) NULL,
  canonical_url VARCHAR(500) NULL,
  internal_links TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blog_posts_slug (slug),
  KEY idx_blog_posts_active (is_active, status),
  KEY idx_blog_posts_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed existing website posts (skips a row if that slug already exists)
INSERT IGNORE INTO blog_posts (
  title, slug, category, subcategory, description, image, link,
  is_active, sort_order, status, published_at, created_at, updated_at
) VALUES
(
  'BLDC Technology Fans Explained – Why Khaitan BLDC Fans Are More Efficient for Indian Homes',
  'bldc-technology-fan-explain',
  'Fans Guide',
  'ceiling-fans',
  '',
  '/assets/img/blogs/bldc-fan-18122025.webp',
  '/blogs/fans-guide/bldc-technology-fan-explain',
  1, 1, 'ACTIVE', NOW(), NOW(), NOW()
),
(
  'The Evolution of Ceiling Fans in India – How Khaitan Redefines Modern Cooling',
  'how-khaitan-redefines-modern-cooling',
  'Fans Guide',
  'ceiling-fans',
  '',
  '/assets/img/blogs/Ceiling-Fans-in-India-19122025.webp',
  '/blogs/fans-guide/how-khaitan-redefines-modern-cooling',
  1, 2, 'ACTIVE', NOW(), NOW(), NOW()
),
(
  'Why Choosing the Right Ceiling Fan Matters – A Complete Buyer’s Guide by Khaitan',
  'why-choosing-the-right-ceiling-fan-matters',
  'Fans Guide',
  'ceiling-fans',
  '',
  '/assets/img/blogs/Ceiling-Fan-Matters-19122025.webp',
  '/blogs/fans-guide/why-choosing-the-right-ceiling-fan-matters',
  1, 3, 'ACTIVE', NOW(), NOW(), NOW()
),
(
  'BLDC Pedestal Fan: The Smart, Silent, Power-Saving Upgrade Your Room Deserves',
  'bldc-pedestal-fan-the-smart-silent-power-saving',
  'Fans Guide',
  'pedestal-fans',
  '',
  '/assets/img/blogs/bldc-pedestal-fan-18022026.webp',
  '/blogs/fans-guide/bldc-pedestal-fan-the-smart-silent-power-saving',
  1, 4, 'ACTIVE', NOW(), NOW(), NOW()
),
(
  'Hot Water in Seconds: The Ultimate Instant Geyser Guide for Indian Homes',
  'hot-water-in-seconds-the-ultimate-instant-geyser',
  'Geysers Guide',
  'instant-geysers',
  '',
  '/assets/img/blogs/hot-water-in-econds-18022026.webp',
  '/blogs/geysers-guide/hot-water-in-seconds-the-ultimate-instant-geyser',
  1, 5, 'ACTIVE', NOW(), NOW(), NOW()
);
