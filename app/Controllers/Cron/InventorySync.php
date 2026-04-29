<?php

namespace App\Controllers\Cron;

use App\Controllers\BaseController;
use App\Models\EasyEcomSkuMappingModel;
use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;

/**
 * Cron: Pull inventory from EasyEcom and update local stock.
 * GET /cron/inventory-sync?secret=<CRON_SECRET>
 */
class InventorySync extends BaseController
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
            'message' => 'EasyEcom inventory-sync cron disabled to avoid auth rate limits.',
            'total'   => 0,
            'updated' => 0,
            'failed'  => [],
        ], 200);

        $enabled = filter_var(env('INVENTORY_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return $this->respond(['success' => true, 'message' => 'Inventory sync disabled (INVENTORY_SYNC_ENABLED=false)', 'updated' => 0]);
        }

        try {
            $client = service('easyecom');
            if (! $client->isConfigured()) {
                return $this->respond(['success' => false, 'message' => 'EasyEcom not configured'], 503);
            }

            $skuMapping   = new EasyEcomSkuMappingModel();
            $productModel = new ProductModel();
            $pageLimit = (int) env('INVENTORY_SYNC_PAGE_LIMIT', 250);
            if ($pageLimit <= 0 || $pageLimit > 1000) {
                $pageLimit = 250;
            }
            $maxPages = (int) env('INVENTORY_SYNC_MAX_PAGES', 100);
            if ($maxPages <= 0) {
                $maxPages = 100;
            }

            $page        = 1;
            $totalItems  = 0;
            $totalUpdated = 0;
            $failed      = [];

            while ($page <= $maxPages) {
                $response = $client->getInventoryDetails([
                    'page'  => $page,
                    'limit' => $pageLimit,
                ]);

                $items = $response['data'] ?? $response['inventory'] ?? $response['items'] ?? [];
                if (! is_array($items) || $items === []) {
                    break;
                }

                foreach ($items as $item) {
                    $eeSku = (string) ($item['sku'] ?? $item['seller_sku'] ?? '');
                    $qty   = isset($item['quantity'])
                        ? (int) $item['quantity']
                        : (isset($item['available_quantity']) ? (int) $item['available_quantity'] : null);

                    if ($eeSku === '' || $qty === null) {
                        continue;
                    }

                    $internalSku = $skuMapping->resolveFromEasyEcom($eeSku);
                    $result      = $productModel->updateStockBySku($internalSku, $qty);

                    if (! empty($result['updated'])) {
                        $totalUpdated++;
                    } else {
                        $failed[] = [
                            'sku'    => $eeSku,
                            'reason' => $result['message'] ?? 'Unknown error',
                        ];
                    }
                }

                $totalItems += count($items);

                if (count($items) < $pageLimit) {
                    break;
                }

                $page++;
            }

            log_message(
                'info',
                'Cron InventorySync: pages=' . ($page - 1) . ' total_items=' . $totalItems . ' updated=' . $totalUpdated . ' failed=' . count($failed)
            );
            return $this->respond([
                'success' => true,
                'total'   => $totalItems,
                'updated' => $totalUpdated,
                'failed'  => $failed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Cron InventorySync: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
