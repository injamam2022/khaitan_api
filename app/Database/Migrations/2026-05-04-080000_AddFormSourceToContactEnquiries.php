<?php



namespace App\Database\Migrations;



use CodeIgniter\Database\Migration;



class AddFormSourceToContactEnquiries extends Migration

{

    public function up(): void

    {

        if ($this->db->tableExists('contact_enquiries') && !$this->db->fieldExists('form_source', 'contact_enquiries')) {

            $this->forge->addColumn('contact_enquiries', [

                'form_source' => [

                    'type'       => 'VARCHAR',

                    'constraint' => 120,

                    'null'       => true,

                ],

            ]);

            log_message('info', 'Migration: Added contact_enquiries.form_source');

        }

    }



    public function down(): void

    {

        if ($this->db->tableExists('contact_enquiries') && $this->db->fieldExists('form_source', 'contact_enquiries')) {

            $this->forge->dropColumn('contact_enquiries', 'form_source');

        }

    }

}

