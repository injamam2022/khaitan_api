<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateContactEnquiriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'email' => ['type' => 'VARCHAR', 'constraint' => 255],
            'phone' => ['type' => 'VARCHAR', 'constraint' => 40],
            'message' => ['type' => 'TEXT', 'null' => true],
            'address' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'country' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'state' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'pin' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'email_sent' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->createTable('contact_enquiries', true);

        log_message('info', 'Migration: Created contact_enquiries table');
    }

    public function down(): void
    {
        $this->forge->dropTable('contact_enquiries', true);
    }
}
