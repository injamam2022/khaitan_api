<?php

namespace App\Models;

use CodeIgniter\Model;

class HomeproductModel extends Model
{
    protected $table = 'product_category';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'category', 'categorykey', 'logo', 'parent_id', 'sequences',
        'home_display_status', 'home_static_image', 'status', 'created_id', 'created_on'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';

    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    public function getProcateList($status = null)
    {
        $builder = $this->db->table('product_category');
        $builder->where('status', 'ACTIVE');
        $builder->where('home_static_image IS NOT NULL');
        $builder->where('home_static_image !=', '');
        $builder->orderBy('sequences', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductList($catId)
    {
        $builder = $this->db->table('products');
        $builder->where('home_display_status', 'YES');
        $builder->where('category_id', (int)$catId);
        $builder->orderBy('home_display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductListfilter($catId)
    {
        $builder = $this->db->table('products');
        $builder->where('status', 'ACTIVE');
        $builder->where('home_display_status', 'NO');
        $builder->where('category_id', (int)$catId);
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductCategoryListfilter($status = null)
    {
        $builder = $this->db->table('product_category');
        
        if ($status === null) {
            $builder->where('status <>', 'DELETED');
        } else {
            $builder->where('status', $status);
        }
        
        $builder->where('home_display_status', 'NO');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductCategoryListAll($status = null)
    {
        $builder = $this->db->table('product_category');
        
        if ($status === null) {
            $builder->where('status <>', 'DELETED');
        } else {
            $builder->where('status', $status);
        }
        
        $builder->groupStart();
        $builder->where('home_static_image IS NULL');
        $builder->orWhere('home_static_image', '');
        $builder->groupEnd();
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
}
