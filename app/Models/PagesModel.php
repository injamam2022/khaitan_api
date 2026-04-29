<?php

namespace App\Models;

use CodeIgniter\Model;

class PagesModel extends Model
{
    protected $table = 'page_content';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'page_title', 'page_key', 'content', 'status', 'created_on', 'updated_on'
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

    public function getPageList($status = null)
    {
        $builder = $this->db->table('page_content');
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }

    public function pageTileExists($pid, $page_title)
    {
        $builder = $this->db->table('page_content');
        $builder->where('page_title', $page_title);
        $builder->where('id <>', (int)$pid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }

    public function insertPage($dataArr)
    {
        $result = $this->insert($dataArr);

        if ($result) {
            return $this->getInsertID();
        } else {
            return false;
        }
    }

    public function getPageDetails($pid)
    {
        $builder = $this->db->table('page_content');
        $builder->where('id', (int)$pid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        $builder->limit(1);
        
        $result = $builder->get()->getResultArray();
        return !empty($result) ? $result[0] : null;
    }

    public function updatePage($pid, $dataArr)
    {
        return $this->update($pid, $dataArr);
    }
}
