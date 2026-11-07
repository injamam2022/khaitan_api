<?php

namespace App\Libraries;

class BannerColumns
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        try {
            $db = \Config\Database::connect();
            if (!$db->tableExists('home_banners')) {
                return;
            }
            if (!$db->fieldExists('placement', 'home_banners')) {
                $db->query("ALTER TABLE home_banners ADD COLUMN placement VARCHAR(64) NOT NULL DEFAULT 'home' AFTER link");
            }
        } catch (\Throwable $e) {
            log_message('error', 'BannerColumns::ensure: ' . $e->getMessage());
        }
    }
}
