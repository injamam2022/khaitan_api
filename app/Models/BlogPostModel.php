<?php

namespace App\Models;

use CodeIgniter\Model;

class BlogPostModel extends Model
{
    protected $table = 'blog_posts';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'title',
        'slug',
        'category',
        'subcategory',
        'description',
        'image',
        'link',
        'content',
        'seo_title',
        'seo_description',
        'is_active',
        'sort_order',
        'status',
        'published_at',
        'created_at',
        'updated_at',
    ];
}
