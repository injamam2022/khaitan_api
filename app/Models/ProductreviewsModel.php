<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductreviewsModel extends Model
{
    protected $table = 'product_reviews';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    
    protected $allowedFields = [
        'product_id', 'user_id', 'customer_name', 
        'star_ratting', 'review', 'review_added_by', 
        'status', 'created_id', 'created_on', 'updated_on'
    ];
    
    protected $useTimestamps = false;
    
    protected $validationRules = [];
    protected $validationMessages = [];
    
    public function getReviewList($status = null)
    {
        $builder = $this->db->table('product_reviews as PR');
        $builder->select('PR.*, P.product_name');
        $builder->join('products as P', 'PR.product_id = P.id', 'left');
        $builder->where('PR.status <>', 'DELETED');
        
        // Filter by status if provided
        if ($status !== null && !empty($status)) {
            $builder->where('PR.status', $status);
        }
        
        $builder->orderBy('PR.id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getReviewDetails($prId)
    {
        // Validate and sanitize ID as integer
        $prId = (int)$prId;
        
        $builder = $this->db->table('product_reviews');
        $builder->where('id', $prId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function insertProductReview($dataArr)
    {
        $result = $this->insert($dataArr);
        
        if ($result) {
            return $this->getInsertID();
        }
        
        return false;
    }
    
    public function updateProductReview($prid, $dataArr)
    {
        $prid = (int)$prid;
        
        return $this->update($prid, $dataArr);
    }
}
