<?php

namespace App\Models;

use CodeIgniter\Model;

class JobApplicationModel extends Model
{
    protected $table = 'apply_job_tables';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'job_opening_id',
        'name',
        'email',
        'phone',
        'message',
        'resume_path',
        'resume_original_name',
        'form_source',
        'ip_address',
        'user_agent',
        'email_sent',
        'created_at',
    ];
}
