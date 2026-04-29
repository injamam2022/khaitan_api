<?php

namespace App\Models;

use CodeIgniter\Model;

class BannerModel extends Model
{
    protected $table = 'home_banners';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'img', 'alt', 'link', 'order', 'is_active', 'created_at', 'updated_at'
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

    /**
     * Get banner list
     * 
     * @return array Banner list
     */
    public function getBannerList()
    {
        $builder = $this->db->table('home_banners');
        $builder->orderBy('order', 'ASC');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    /**
     * Get banner details by ID
     * 
     * @param int $bannerId Banner ID
     * @return array|null Banner details or null if not found
     */
    public function getBannerDetails($bannerId)
    {
        $builder = $this->db->table('home_banners');
        $builder->where('id', (int)$bannerId);
        $builder->limit(1);
        
        $result = $builder->get()->getResultArray();
        return !empty($result) ? $result[0] : null;
    }
    
    /**
     * Insert banner
     * 
     * @param array $dataArr Banner data
     * @return int|false Inserted banner ID or false on failure
     */
    public function insertBanner($dataArr)
    {
        $result = $this->insert($dataArr);

        if ($result) {
            return $this->getInsertID();
        } else {
            return false;
        }
    }
    
    /**
     * Update banner
     * 
     * @param int $bannerId Banner ID
     * @param array $dataArr Banner data to update
     * @return bool True on success, false on failure
     */
    public function updateBanner($bannerId, $dataArr)
    {
        return $this->update($bannerId, $dataArr);
    }
    
    /**
     * Delete banner
     * 
     * @param int $bannerId Banner ID
     * @return bool True on success, false on failure
     */
    public function deleteBanner($bannerId)
    {
        return $this->delete($bannerId);
    }
}
