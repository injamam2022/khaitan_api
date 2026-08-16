<?php

namespace App\Models;

use CodeIgniter\Model;

class JobOpeningModel extends Model
{
    protected $table = 'create_job_tables';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'title',
        'slug',
        'job_type',
        'location',
        'level',
        'years_experience',
        'description',
        'is_active',
        'sort_order',
        'status',
        'created_at',
        'updated_at',
    ];
}
