<?php

namespace App\Models;

use CodeIgniter\Model;

class SliderModel extends Model
{
    protected $table = 'home_slider';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'title', 'image', 'link_url', 'sequences', 'status', 'created_on', 'updated_on'
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

    public function getSliderList($status = null)
    {
        $builder = $this->db->table('home_slider');
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getSliderDetails($sId)
    {
        $builder = $this->db->table('home_slider');
        $builder->where('status <>', 'DELETED');
        $builder->where('id', (int)$sId);
        $builder->orderBy('id', 'DESC');
        $builder->limit(1);
        
        $result = $builder->get()->getResultArray();
        return !empty($result) ? $result[0] : null;
    }
    
    public function insertSlider($dataArr)
    {
        $result = $this->insert($dataArr);

        if ($result) {
            return $this->getInsertID();
        } else {
            return false;
        }
    }
    
    public function updateSlider($sid, $dataArr)
    {
        return $this->update($sid, $dataArr);
    }
}
