<?php

namespace App\Controllers\Cron;

use App\Controllers\BaseController;
use App\Models\EasyEcomSkuMappingModel;
use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;

/**
 * Cron: Push our database stock to EasyEcom.
 * GET /cron/stock-sync-push?secret=<CRON_SECRET>
 *
 * Addresses legacy products where EasyEcom stock is 0 while our DB has valid quantities.
 * Uses Virtual Inventory API (POST /updateVirtualInventoryAPI). No impact on product sync or order flows.
 */
class StockSyncPush extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $secret = env('CRON_SECRET', '');
        if ($secret !== '' && $this->request->getGet('secret') !== $secret) {
            return $this->respond(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Disabled to avoid EasyEcom auth rate limits (429). Remove this block to re-enable.
        return $this->respond([
            'success' => true,
            'message' => 'EasyEcom stock-sync-push cron disabled to avoid auth rate limits.',
            'synced'  => 0,
        ], 200);

        $enabled = filter_var(env('STOCK_SYNC_PUSH_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return $this->respond([
                'success' => true,
                'message' => 'Stock sync push disabled (STOCK_SYNC_PUSH_ENABLED=false)',
                'synced'  => 0,
            ]);
        }

        try {
            $client = service('easyecom');
            if (! $client instanceof \App\Libraries\EasyEcomClient || ! $client->isConfigured()) {
                return $this->respond(['success' => false, 'message' => 'EasyEcom not configured'], 503);
            }

            $productModel    = new ProductModel();
            $skuMapping     = new EasyEcomSkuMappingModel();
            $batchSize      = max(1, min(100, (int) env('STOCK_SYNC_PUSH_BATCH_SIZE', 50)));
            $items          = $productModel->getAllStockableSkusWithQuantity();

            if ($items === []) {
                return $this->respond([
                    'success' => true,
                    'total'   => 0,
                    'synced'  => 0,
                    'failed'  => [],
                ]);
            }

            $payload = [];
            foreach ($items as $item) {
                $resolvedSku = $skuMapping->resolveForEasyEcom($item['sku']);
                $payload[] = [
                    'sku'      => $resolvedSku,
                    'quantity' => $item['quantity'],
                ];
            }

            $batches = array_chunk($payload, $batchSize);
            $synced  = 0;
            $failed  = [];

            foreach ($batches as $batch) {
                try {
                    $client->bulkInventoryUpdate($batch);
                    $synced += count($batch);
                } catch (\Throwable $e) {
                    foreach ($batch as $b) {
                        try {
                            $client->updateInventory($b['sku'], $b['quantity']);
                            $synced++;
                        } catch (\Throwable $e2) {
                            $failed[] = ['sku' => $b['sku'], 'reason' => $e2->getMessage()];
                        }
                    }
                }
            }

            log_message('info', 'Cron StockSyncPush: total=' . count($items) . ' synced=' . $synced . ' failed=' . count($failed));
            return $this->respond([
                'success' => true,
                'total'   => count($items),
                'synced'  => $synced,
                'failed'  => $failed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Cron StockSyncPush: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
