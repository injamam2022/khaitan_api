<?php

namespace App\Libraries;

class PageSeoTables
{
    public const PAGES = [
        'home' => [
            'label' => 'Home',
            'path' => '/',
            'meta_title' => 'Khaitan Appliances & Fans | Water Heaters, Coolers, Kitchen & Home Appliances',
            'meta_description' => 'Explore Khaitan’s wide range of home appliances including ceiling fans, ventilation fans, water heaters, air coolers, mixer grinders, heaters, and kitchen appliances. Trusted performance, energy efficiency, modern designs & nationwide service support.',
            'meta_keywords' => 'Khaitan, ceiling fans, BLDC fans, water heaters, geysers, air coolers, mixer grinders, kitchen appliances, home appliances',
        ],
        'blogs' => [
            'label' => 'Blogs',
            'path' => '/blogs',
            'meta_title' => 'Khaitan Blogs | Fans, Geysers & Home Appliance Guides',
            'meta_description' => 'Read Khaitan guides on ceiling fans, BLDC technology, geysers, coolers and home appliances. Practical tips to choose, use and maintain products for Indian homes.',
            'meta_keywords' => 'Khaitan blogs, ceiling fan guide, BLDC fans, geyser guide, home appliance tips, Khaitan articles',
        ],
        'about-us' => [
            'label' => 'About Us',
            'path' => '/about-us',
            'meta_title' => 'About Khaitan | India’s Durable Fans & Home Appliances Brand',
            'meta_description' => 'Khaitan is one of India’s most loved durable brands for ceiling fans, water heaters, air coolers, kitchen and home appliances — trusted performance and nationwide service.',
            'meta_keywords' => 'about Khaitan, Khaitan brand, Indian home appliances, Khaitan fans, Khaitan geysers',
        ],
        'contact-us' => [
            'label' => 'Contact Us',
            'path' => '/contact-us',
            'meta_title' => 'Contact Khaitan | Customer Care, WhatsApp & Support',
            'meta_description' => 'Get in touch with Khaitan for product support, warranty help or enquiries. Call, WhatsApp or write to our customer care team for prompt assistance.',
            'meta_keywords' => 'contact Khaitan, Khaitan customer care, Khaitan support, Khaitan WhatsApp, Khaitan helpline',
        ],
    ];

    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        try {
            $db = \Config\Database::connect();
            $db->query("CREATE TABLE IF NOT EXISTS page_seo (
              slug VARCHAR(64) NOT NULL,
              label VARCHAR(80) NOT NULL,
              meta_title VARCHAR(255) NULL,
              meta_description VARCHAR(512) NULL,
              meta_keywords VARCHAR(512) NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach (self::PAGES as $slug => $page) {
                $existing = $db->table('page_seo')->where('slug', $slug)->get()->getRowArray();
                if (!empty($existing)) {
                    continue;
                }
                $db->table('page_seo')->insert([
                    'slug' => $slug,
                    'label' => $page['label'],
                    'meta_title' => $page['meta_title'],
                    'meta_description' => $page['meta_description'],
                    'meta_keywords' => $page['meta_keywords'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'PageSeoTables::ensure: ' . $e->getMessage());
        }
    }

    public static function allowedSlugs(): array
    {
        return array_keys(self::PAGES);
    }
}
