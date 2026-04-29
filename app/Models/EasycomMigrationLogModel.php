<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Logs for EasyEcom product ID backfill migration (CLI).
 * Each row records success or failure for one product sync attempt.
 */
class EasycomMigrationLogModel extends Model
{
    protected $table            = 'easycom_migration_logs';
    protected $primaryKey      = 'id';
    protected $useAutoIncrement = true;
    protected $returnType      = 'array';
    protected $useSoftDeletes  = false;
    protected $allowedFields   = [
        'product_id',
        'sku',
        'status',
        'response_message',
        'created_at',
    ];
    protected $useTimestamps   = true;
    protected $createdField    = 'created_at';
    protected $updatedField    = null;
    protected $dateFormat      = 'datetime';
}
