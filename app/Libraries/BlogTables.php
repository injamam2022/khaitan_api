<?php

namespace App\Libraries;

class BlogTables
{
    public static function ensure(): void
    {
        $db = \Config\Database::connect();

        $db->query("CREATE TABLE IF NOT EXISTS blog_posts (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(255) NOT NULL,
          slug VARCHAR(180) NOT NULL,
          category VARCHAR(120) NOT NULL DEFAULT '',
          subcategory VARCHAR(120) NOT NULL DEFAULT '',
          description TEXT NULL,
          image VARCHAR(500) NULL,
          link VARCHAR(500) NULL,
          content LONGTEXT NULL,
          seo_title VARCHAR(180) NULL,
          seo_description VARCHAR(320) NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if ((int) $db->table('blog_posts')->countAllResults() === 0) {
            self::seed($db);
        }
    }

    private static function seed($db): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            [
                'title' => 'BLDC Technology Fans Explained – Why Khaitan BLDC Fans Are More Efficient for Indian Homes',
                'slug' => 'bldc-technology-fan-explain',
                'category' => 'Fans Guide',
                'subcategory' => 'ceiling-fans',
                'description' => '',
                'image' => '/assets/img/blogs/bldc-fan-18122025.webp',
                'link' => '/blogs/fans-guide/bldc-technology-fan-explain',
                'sort_order' => 1,
            ],
            [
                'title' => 'The Evolution of Ceiling Fans in India – How Khaitan Redefines Modern Cooling',
                'slug' => 'how-khaitan-redefines-modern-cooling',
                'category' => 'Fans Guide',
                'subcategory' => 'ceiling-fans',
                'description' => '',
                'image' => '/assets/img/blogs/Ceiling-Fans-in-India-19122025.webp',
                'link' => '/blogs/fans-guide/how-khaitan-redefines-modern-cooling',
                'sort_order' => 2,
            ],
            [
                'title' => 'Why Choosing the Right Ceiling Fan Matters – A Complete Buyer’s Guide by Khaitan',
                'slug' => 'why-choosing-the-right-ceiling-fan-matters',
                'category' => 'Fans Guide',
                'subcategory' => 'ceiling-fans',
                'description' => '',
                'image' => '/assets/img/blogs/Ceiling-Fan-Matters-19122025.webp',
                'link' => '/blogs/fans-guide/why-choosing-the-right-ceiling-fan-matters',
                'sort_order' => 3,
            ],
            [
                'title' => 'BLDC Pedestal Fan: The Smart, Silent, Power-Saving Upgrade Your Room Deserves',
                'slug' => 'bldc-pedestal-fan-the-smart-silent-power-saving',
                'category' => 'Fans Guide',
                'subcategory' => 'pedestal-fans',
                'description' => '',
                'image' => '/assets/img/blogs/bldc-pedestal-fan-18022026.webp',
                'link' => '/blogs/fans-guide/bldc-pedestal-fan-the-smart-silent-power-saving',
                'sort_order' => 4,
            ],
            [
                'title' => 'Hot Water in Seconds: The Ultimate Instant Geyser Guide for Indian Homes',
                'slug' => 'hot-water-in-seconds-the-ultimate-instant-geyser',
                'category' => 'Geysers Guide',
                'subcategory' => 'instant-geysers',
                'description' => '',
                'image' => '/assets/img/blogs/hot-water-in-econds-18022026.webp',
                'link' => '/blogs/geysers-guide/hot-water-in-seconds-the-ultimate-instant-geyser',
                'sort_order' => 5,
            ],
        ];

        foreach ($rows as $row) {
            $db->table('blog_posts')->insert(array_merge($row, [
                'content' => null,
                'seo_title' => null,
                'seo_description' => null,
                'is_active' => 1,
                'status' => 'ACTIVE',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
}
