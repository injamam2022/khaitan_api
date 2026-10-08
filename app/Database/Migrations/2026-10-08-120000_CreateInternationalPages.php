<?php

namespace App\Database\Migrations;

use App\Models\InternationalPageModel;
use CodeIgniter\Database\Migration;

class CreateInternationalPages extends Migration
{
    public function up(): void
    {
        $model = new InternationalPageModel();
        $model->ensureSchema();
    }

    public function down(): void
    {
        $this->forge->dropTable('intl_enquiries', true);
        $this->forge->dropTable('intl_folds', true);
        $this->forge->dropTable('intl_pages', true);
    }
}
