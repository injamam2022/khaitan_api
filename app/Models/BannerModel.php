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
        'img', 'alt', 'link', 'placement', 'order', 'is_active', 'created_at', 'updated_at'
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
     * Rewrite legacy /backend/assets/ URLs to the current public assets path.
     */
    private function publicImageUrl(?string $img): string
    {
        $img = trim((string) $img);
        if ($img === '') {
            return '';
        }

        if (preg_match('#/(?:backend|khaitan_api/public)/assets/(.+)$#i', $img, $match)) {
            return base_url('assets/' . ltrim($match[1], '/'));
        }

        if (preg_match('#^https?://#i', $img)) {
            return preg_replace('#/index\\.php#', '', $img) ?: $img;
        }

        return base_url('assets/banners/' . ltrim($img, '/'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withPublicImage(array $row): array
    {
        $row['img'] = $this->publicImageUrl($row['img'] ?? '');
        return $row;
    }

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
        
        return array_map(
            fn (array $row): array => $this->withPublicImage($row),
            $builder->get()->getResultArray()
        );
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
        return !empty($result) ? $this->withPublicImage($result[0]) : null;
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
