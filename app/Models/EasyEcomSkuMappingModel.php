<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Map internal SKU (our product/variation) to EasyEcom SKU and vice versa.
 * - Push order: internal_sku -> easyecom_sku for order_lines.
 * - Inventory webhook: easyecom_sku -> internal_sku for updating our stock.
 */
class EasyEcomSkuMappingModel extends Model
{
    protected $table;
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['internal_sku', 'easyecom_sku', 'created_at', 'updated_at'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    public function __construct()
    {
        $this->table = config(\Config\EasyEcom::class)->skuMappingTable ?? 'easyecom_sku_mapping';
        parent::__construct();
    }

    /**
     * Get EasyEcom SKU for a given internal SKU (for pushing orders).
     *
     * @param string $internalSku Our sku_number or variation sku
     * @return string|null EasyEcom SKU if mapped, null to use internal as-is
     */
    public function getEasyEcomSku(string $internalSku): ?string
    {
        if ($internalSku === '') {
            return null;
        }
        try {
            $row = $this->db->table($this->table)
                ->where('internal_sku', $internalSku)
                ->limit(1)
                ->get()
                ->getRowArray();
            return $row ? $row['easyecom_sku'] : null;
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcomSkuMapping getEasyEcomSku: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get internal SKU for a given EasyEcom SKU (for inventory webhook).
     *
     * @param string $easyecomSku SKU from EasyEcom payload
     * @return string|null Our SKU if mapped, null to use EasyEcom SKU as-is
     */
    public function getInternalSku(string $easyecomSku): ?string
    {
        if ($easyecomSku === '') {
            return null;
        }
        try {
            $row = $this->db->table($this->table)
                ->where('easyecom_sku', $easyecomSku)
                ->limit(1)
                ->get()
                ->getRowArray();
            return $row ? $row['internal_sku'] : null;
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcomSkuMapping getInternalSku: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve SKU for outbound: if mapping exists return EasyEcom SKU, else return internal.
     * If the mapping table is missing or errors, falls back to internal SKU so order push can continue.
     */
    public function resolveForEasyEcom(string $internalSku): string
    {
        try {
            $mapped = $this->getEasyEcomSku($internalSku);
            $resolved = $mapped !== null ? $mapped : $internalSku;
            log_message('info', 'EasyEcom: [SKU_RESOLVE] outbound internal=' . $internalSku . ' -> easyecom=' . $resolved . ($mapped !== null ? ' (mapped) SUCCESS' : ' (passthrough) SUCCESS'));
            return $resolved;
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [SKU_RESOLVE] outbound error: ' . $e->getMessage());
            return $internalSku;
        }
    }

    /**
     * Return number of rows in mapping table (for debug logging).
     */
    public function getMappingCount(): int
    {
        try {
            return (int) $this->db->table($this->table)->countAll();
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcomSkuMapping getMappingCount: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Resolve SKU for inbound (webhook): if mapping exists return internal SKU, else return EasyEcom SKU.
     */
    public function resolveFromEasyEcom(string $easyecomSku): string
    {
        $mapped = $this->getInternalSku($easyecomSku);
        $resolved = $mapped !== null ? $mapped : $easyecomSku;
        log_message('info', 'EasyEcom: [SKU_RESOLVE] inbound easyecom=' . $easyecomSku . ' -> internal=' . $resolved . ($mapped !== null ? ' (mapped) SUCCESS' : ' (passthrough) SUCCESS'));
        return $resolved;
    }
}
