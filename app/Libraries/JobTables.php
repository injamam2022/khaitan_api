<?php

namespace App\Libraries;

class JobTables
{
    public static function ensure(): void
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('job_openings') && !$db->tableExists('create_job_tables')) {
            $db->query('RENAME TABLE job_openings TO create_job_tables');
        }
        if ($db->tableExists('job_applications') && !$db->tableExists('apply_job_tables')) {
            $db->query('RENAME TABLE job_applications TO apply_job_tables');
        }

        $db->query("CREATE TABLE IF NOT EXISTS create_job_tables (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(200) NOT NULL,
          slug VARCHAR(160) NOT NULL,
          job_type VARCHAR(50) NOT NULL DEFAULT 'Full Time',
          location VARCHAR(100) NOT NULL DEFAULT '',
          level VARCHAR(50) NOT NULL DEFAULT '',
          years_experience VARCHAR(20) NOT NULL DEFAULT '',
          description TEXT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          status ENUM('ACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_create_job_tables_slug (slug),
          KEY idx_create_job_tables_active (is_active, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $existing = $db->table('create_job_tables')->countAllResults();
        if ($existing === 0) {
            $db->table('create_job_tables')->insert([
                'title' => 'Creative Director',
                'slug' => 'creative-director',
                'job_type' => 'Full Time',
                'location' => 'Kolkata',
                'level' => 'Senior',
                'years_experience' => '7',
                'description' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->query("CREATE TABLE IF NOT EXISTS apply_job_tables (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          job_opening_id INT UNSIGNED NULL,
          name VARCHAR(200) NOT NULL,
          email VARCHAR(255) NOT NULL,
          phone VARCHAR(40) NOT NULL,
          message TEXT NULL,
          resume_path VARCHAR(512) NULL,
          resume_original_name VARCHAR(255) NULL,
          form_source VARCHAR(120) NULL,
          ip_address VARCHAR(45) NULL,
          user_agent VARCHAR(500) NULL,
          email_sent TINYINT(1) NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_apply_job_tables_job (job_opening_id),
          KEY idx_apply_job_tables_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
