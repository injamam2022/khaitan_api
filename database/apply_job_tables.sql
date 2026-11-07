CREATE TABLE IF NOT EXISTS apply_job_tables (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
