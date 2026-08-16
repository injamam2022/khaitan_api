<?php

namespace App\Libraries;

class CategorySeoColumns
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        $db = \Config\Database::connect();
        if (!$db->tableExists('product_category')) {
            return;
        }

        $columns = [
            'meta_title' => "VARCHAR(255) NULL DEFAULT NULL",
            'meta_description' => "VARCHAR(512) NULL DEFAULT NULL",
            'meta_keywords' => "VARCHAR(512) NULL DEFAULT NULL",
        ];

        foreach ($columns as $name => $definition) {
            if (!$db->fieldExists($name, 'product_category')) {
                $db->query("ALTER TABLE product_category ADD COLUMN {$name} {$definition}");
            }
        }
    }
}
