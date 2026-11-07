<?php

namespace App\Models;

use CodeIgniter\Model;

class PageSeoModel extends Model
{
    protected $table = 'page_seo';
    protected $primaryKey = 'slug';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'slug',
        'label',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'updated_at',
    ];
    protected $useTimestamps = false;
}
