<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Maps local orders to EasyEcom orders for order integration.
 * Fields: local_order_id, easyecom_order_id, marketplace, status, created_at.
 */
class EasyEcomOrderMappingModel extends Model
{
    protected $table            = 'easyecom_order_mapping';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'local_order_id',
        'easyecom_order_id',
        'marketplace',
        'status',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Insert or update mapping for a local order after EasyEcom order creation.
     *
     * @param int         $localOrderId   Our orders.id
     * @param string|null $easyecomOrderId EasyEcom order ID from API response
     * @param string      $marketplace    Marketplace identifier (e.g. "website" or config marketplace ID)
     * @param string      $status         e.g. "created", "failed"
     * @return int|bool Insert ID or true on update, false on failure
     */
    public function saveMapping(int $localOrderId, ?string $easyecomOrderId, string $marketplace = 'website', string $status = 'created')
    {
        $existing = $this->where('local_order_id', $localOrderId)->first();
        $data = [
            'local_order_id'    => $localOrderId,
            'easyecom_order_id' => $easyecomOrderId,
            'marketplace'       => $marketplace,
            'status'            => $status,
        ];
        if ($existing) {
            return $this->update($existing['id'], $data);
        }
        return $this->insert($data) ? $this->getInsertID() : false;
    }

    /**
     * Get EasyEcom order ID for a local order if mapped.
     *
     * @param int $localOrderId orders.id
     * @return string|null easyecom_order_id or null
     */
    public function getEasyEcomOrderId(int $localOrderId): ?string
    {
        $row = $this->where('local_order_id', $localOrderId)->first();
        return $row && ! empty($row['easyecom_order_id']) ? (string) $row['easyecom_order_id'] : null;
    }
}
