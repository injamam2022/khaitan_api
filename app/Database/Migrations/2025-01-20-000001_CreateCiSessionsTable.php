<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create ci_sessions table for CodeIgniter 4 database session handler
 * 
 * This migration creates the required table structure for storing sessions
 * in the database instead of files.
 * 
 * Table structure matches CodeIgniter 4 requirements:
 * - id: VARCHAR(128) - Session ID (primary key)
 * - ip_address: VARCHAR(45) - User IP address (supports IPv6)
 * - timestamp: INT(10) UNSIGNED - Session timestamp for garbage collection
 * - data: BLOB - Serialized session data
 */
class CreateCiSessionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'VARCHAR',
                'constraint'     => 128,
                'null'           => false,
            ],
            'ip_address' => [
                'type'           => 'VARCHAR',
                'constraint'     => 45,
                'null'           => false,
            ],
            'timestamp' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'default'        => 0,
                'null'           => false,
            ],
            'data' => [
                'type'           => 'BLOB',
                'null'           => true,
            ],
        ]);
        
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('timestamp');
        $this->forge->createTable('ci_sessions', true);
        
        log_message('info', 'Migration: Created ci_sessions table for database session handler');
    }

    public function down()
    {
        $this->forge->dropTable('ci_sessions', true);
        
        log_message('info', 'Migration: Dropped ci_sessions table');
    }
}
