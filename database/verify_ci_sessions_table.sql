-- ============================================================
-- Verify and Fix ci_sessions Table Structure
-- ============================================================
-- Run this script to check if your ci_sessions table has
-- the correct structure for CodeIgniter 4 DatabaseHandler
-- ============================================================

USE khaitan;

-- ============================================================
-- Step 1: Check if table exists
-- ============================================================
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Table exists'
        ELSE '❌ Table does not exist'
    END as table_status
FROM information_schema.tables 
WHERE table_schema = 'khaitan' 
AND table_name = 'ci_sessions';

-- ============================================================
-- Step 2: Show current table structure
-- ============================================================
DESCRIBE ci_sessions;

-- ============================================================
-- Step 3: Show indexes
-- ============================================================
SHOW INDEXES FROM ci_sessions;

-- ============================================================
-- Step 4: Check record count
-- ============================================================
SELECT COUNT(*) as total_sessions FROM ci_sessions;

-- ============================================================
-- Step 5: Show sample records (latest 5)
-- ============================================================
SELECT 
    id,
    ip_address,
    timestamp,
    FROM_UNIXTIME(timestamp) as created_at,
    LENGTH(data) as data_length
FROM ci_sessions 
ORDER BY timestamp DESC 
LIMIT 5;

-- ============================================================
-- FIXES (only run if needed)
-- ============================================================

-- Fix 1: Add missing columns (if any)
-- ALTER TABLE ci_sessions ADD COLUMN IF NOT EXISTS id VARCHAR(128) NOT NULL FIRST;
-- ALTER TABLE ci_sessions ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NOT NULL AFTER id;
-- ALTER TABLE ci_sessions ADD COLUMN IF NOT EXISTS timestamp INT(10) UNSIGNED DEFAULT 0 NOT NULL AFTER ip_address;
-- ALTER TABLE ci_sessions ADD COLUMN IF NOT EXISTS data BLOB AFTER timestamp;

-- Fix 2: Fix column types (if wrong)
-- ALTER TABLE ci_sessions MODIFY COLUMN id VARCHAR(128) NOT NULL;
-- ALTER TABLE ci_sessions MODIFY COLUMN ip_address VARCHAR(45) NOT NULL;
-- ALTER TABLE ci_sessions MODIFY COLUMN timestamp INT(10) UNSIGNED DEFAULT 0 NOT NULL;
-- ALTER TABLE ci_sessions MODIFY COLUMN data BLOB;

-- Fix 3: Add PRIMARY KEY (if missing)
-- ALTER TABLE ci_sessions ADD PRIMARY KEY (id);

-- Fix 4: Add INDEX on timestamp (if missing)
-- CREATE INDEX ci_sessions_timestamp ON ci_sessions(timestamp);

-- ============================================================
-- COMPLETE FIX (recreate table with correct structure)
-- ============================================================
-- WARNING: This will DELETE all existing sessions!
-- Only run if you're sure you want to recreate the table.
-- ============================================================

/*
DROP TABLE IF EXISTS ci_sessions;

CREATE TABLE ci_sessions (
  id VARCHAR(128) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  timestamp INT(10) UNSIGNED DEFAULT 0 NOT NULL,
  data BLOB,
  PRIMARY KEY (id),
  KEY ci_sessions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/

-- ============================================================
-- VERIFICATION QUERY
-- ============================================================
-- Run this to verify table structure matches requirements:
-- ============================================================

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'khaitan'
AND TABLE_NAME = 'ci_sessions'
ORDER BY ORDINAL_POSITION;

-- Expected Result:
-- id          | varchar(128)     | NO  | NULL | PRI
-- ip_address  | varchar(45)      | NO  | NULL |
-- timestamp   | int(10) unsigned | NO  | 0    | MUL
-- data        | blob             | YES | NULL |
