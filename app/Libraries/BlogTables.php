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
          image_alt VARCHAR(255) NULL,
          link VARCHAR(500) NULL,
          content LONGTEXT NULL,
          seo_title VARCHAR(180) NULL,
          seo_description VARCHAR(320) NULL,
          seo_keywords VARCHAR(512) NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureColumn($db, 'image_alt', 'VARCHAR(255) NULL');
        self::ensureColumn($db, 'canonical_url', 'VARCHAR(500) NULL');
        self::ensureColumn($db, 'internal_links', 'TEXT NULL');
        self::ensureColumn($db, 'seo_keywords', 'VARCHAR(512) NULL');

        if ((int) $db->table('blog_posts')->countAllResults() === 0) {
            self::seed($db);
        }

        self::backfillBodies($db);
    }

    private static function backfillBodies($db): void
    {
        if (!class_exists(BlogPostBodies::class)) {
            return;
        }

        foreach (BlogPostBodies::all() as $slug => $body) {
            $row = $db->table('blog_posts')->where('slug', $slug)->where('status <>', 'DELETED')->get()->getRowArray();
            if (!$row) {
                continue;
            }

            $update = [];
            if (trim((string) ($row['content'] ?? '')) === '' && !empty($body['content'])) {
                $update['content'] = $body['content'];
            }
            if (trim((string) ($row['description'] ?? '')) === '' && !empty($body['description'])) {
                $update['description'] = $body['description'];
            }
            if (trim((string) ($row['image_alt'] ?? '')) === '' && !empty($row['title'])) {
                $update['image_alt'] = $row['title'];
            }
            if (trim((string) ($row['seo_title'] ?? '')) === '' && !empty($row['title'])) {
                $update['seo_title'] = mb_substr((string) $row['title'], 0, 180);
            }
            if (trim((string) ($row['seo_description'] ?? '')) === '' && !empty($body['description'])) {
                $update['seo_description'] = mb_substr((string) $body['description'], 0, 320);
            }
            if (trim((string) ($row['canonical_url'] ?? '')) === '' && !empty($row['link'])) {
                $link = (string) $row['link'];
                $update['canonical_url'] = str_starts_with($link, 'http')
                    ? $link
                    : 'https://www.khaitan.com' . (str_starts_with($link, '/') ? $link : '/' . $link);
            }

            if ($update !== []) {
                $update['updated_at'] = date('Y-m-d H:i:s');
                $db->table('blog_posts')->where('id', $row['id'])->update($update);
            }
        }
    }

    private static function ensureColumn($db, string $name, string $definition): void
    {
        if (!$db->fieldExists($name, 'blog_posts')) {
            $db->query("ALTER TABLE blog_posts ADD COLUMN {$name} {$definition}");
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

        $bodies = class_exists(BlogPostBodies::class) ? BlogPostBodies::all() : [];

        foreach ($rows as $row) {
            $body = $bodies[$row['slug']] ?? ['content' => null, 'description' => ''];
            $description = $row['description'] !== '' ? $row['description'] : ($body['description'] ?? '');
            $link = (string) $row['link'];
            $db->table('blog_posts')->insert([
                'title' => $row['title'],
                'slug' => $row['slug'],
                'category' => $row['category'],
                'subcategory' => $row['subcategory'],
                'description' => $description !== '' ? $description : null,
                'image' => $row['image'],
                'image_alt' => $row['title'],
                'link' => $link,
                'content' => $body['content'] ?? null,
                'seo_title' => mb_substr($row['title'], 0, 180),
                'seo_description' => $description !== '' ? mb_substr($description, 0, 320) : null,
                'canonical_url' => 'https://www.khaitan.com' . $link,
                'internal_links' => null,
                'is_active' => 1,
                'sort_order' => $row['sort_order'],
                'status' => 'ACTIVE',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
