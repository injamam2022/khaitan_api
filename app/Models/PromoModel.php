<?php

namespace App\Models;

use CodeIgniter\Model;

class PromoModel extends Model
{
    protected $table = 'promo';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'promo_code', 'discount_percent', 'discount_amount', 'min_order_amount',
        'max_discount', 'valid_from', 'valid_to', 'usage_limit', 'used_count',
        'status', 'user_id', 'fullname', 'promo1', 'is_primary'
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

    public function save_promo($promo)
    {
        // Check if promo_code already exists
        if (isset($promo['promo_code']) && !empty($promo['promo_code'])) {
            $builder = $this->db->table('promo');
            $builder->where('promo_code', $promo['promo_code']);
            $existing = $builder->get()->getRowArray();
            if ($existing) {
                return "exist";
            }
        }

        // Legacy check for old promo structure (keep for backward compatibility)
        if (isset($promo['user_id']) && isset($promo['fullname']) && isset($promo['promo1'])) {
            $builder = $this->db->table('promo');
            $builder->where('user_id', $promo['user_id']);
            $builder->where('fullname', $promo['fullname']);
            $builder->where('promo1', $promo['promo1']);
            if ($builder->countAllResults() > 0) {
                return "exist";
            }
        }

        if (isset($promo['is_primary']) && $promo['is_primary'] == 1) {
            $user_id = $promo['user_id'] ?? null;
            if ($user_id) {
                $builder = $this->db->table('promo');
                $builder->where('user_id', $user_id);
                $builder->update(['is_primary' => 0]);
            }
        }
        
        $result = $this->insert($promo);
        if ($result) {
            $id = $this->getInsertID();
            return $this->find($id);
        }
        return false;
    }
    
    public function get_promo($status = "")
    {
        $builder = $this->db->table('promo');
        
        if ($status != "") {
            $builder->where('status', $status);
        }
        
        $res = $builder->get()->getResultArray();
        
        // Map schema fields to code expectations for all results
        foreach ($res as &$promo) {
            // Determine promo_type based on discount_percent vs discount_amount
            if (!empty($promo['discount_percent']) && $promo['discount_percent'] > 0) {
                $promo['promo_type'] = 'percent';
                $promo['promo_value'] = (float)$promo['discount_percent'];
            } elseif (!empty($promo['discount_amount']) && $promo['discount_amount'] > 0) {
                $promo['promo_type'] = 'flat';
                $promo['promo_value'] = (float)$promo['discount_amount'];
            } else {
                // Default to percent if both are empty
                $promo['promo_type'] = 'percent';
                $promo['promo_value'] = 0;
            }
            
            // Map date fields
            $promo['start_date'] = $promo['valid_from'];
            $promo['end_date'] = $promo['valid_to'];
            
            // Map max_amount
            $promo['max_amount'] = $promo['max_discount'];
        }
        
        return $res;
    }

    public function promo_details($promo_code)
    {
        $builder = $this->db->table('promo');
        $builder->where('promo_code', $promo_code);
        $res = $builder->get()->getRowArray();
        
        if ($res) {
            // Map schema fields to code expectations for compatibility
            // Determine promo_type based on discount_percent vs discount_amount
            if (!empty($res['discount_percent']) && $res['discount_percent'] > 0) {
                $res['promo_type'] = 'percent';
                $res['promo_value'] = (float)$res['discount_percent'];
            } elseif (!empty($res['discount_amount']) && $res['discount_amount'] > 0) {
                $res['promo_type'] = 'flat';
                $res['promo_value'] = (float)$res['discount_amount'];
            } else {
                // Default to percent if both are empty (shouldn't happen)
                $res['promo_type'] = 'percent';
                $res['promo_value'] = 0;
            }
            
            // Map date fields
            $res['start_date'] = $res['valid_from'];
            $res['end_date'] = $res['valid_to'];
            
            // Map max_amount
            $res['max_amount'] = $res['max_discount'];
        }
        
        return $res ? (object)$res : null;
    }

    public function update_promo($promo, $id)
    {
        // Check if promo_code conflicts with existing promo (if promo_code is being updated)
        if (isset($promo['promo_code']) && !empty($promo['promo_code'])) {
            $builder = $this->db->table('promo');
            $builder->where('promo_code', $promo['promo_code']);
            $builder->where('id !=', $id);
            $existing = $builder->get()->getRowArray();
            if ($existing) {
                return "exist";
            }
        }

        // Legacy check for old promo structure (keep for backward compatibility)
        if (isset($promo['user_id']) && isset($promo['fullname']) && isset($promo['promo1'])) {
            $builder = $this->db->table('promo');
            $builder->where('user_id', $promo['user_id']);
            $builder->where('fullname', $promo['fullname']);
            $builder->where('promo1', $promo['promo1']);
            $builder->where('id !=', $id);
            if ($builder->countAllResults() > 0) {
                return "exist";
            }
        }
       
        $result = $this->update($id, $promo);
        
        if ($result) {
            return $this->find($id);
        }
        return false;
    }

    public function increment_used_count($promo_id)
    {
        $builder = $this->db->table('promo');
        $builder->where('id', $promo_id);
        $builder->set('used_count', 'used_count + 1', false);
        return $builder->update();
    }
}
