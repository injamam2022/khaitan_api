<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\EasyEcomClient;
use App\Libraries\EasyEcomPayloadBuilder;
use App\Models\EasycomMigrationLogModel;
use App\Models\ProductModel;
use App\Models\EasyEcomSkuMappingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Variation backfill script: sync product_variations where easyecom_product_id IS NULL.
 *
 * - Queries product_variations where easyecom_product_id IS NULL
 * - For each variation, pushes as a separate "Master Product" to EasyEcom.
 * - Updates product_variations.easyecom_product_id on success.
 */
class EasyecomVariationBackfill extends BaseCommand
{
    protected $group       = 'EasyEcom';
    protected $name        = 'easyecom:variation-backfill';
    protected $description = 'Backfill EasyEcom product IDs for existing product variations.';
    protected $usage       = 'easyecom:variation-backfill [--delay=1] [--dry-run] [--sync-all]';

    protected $options = [
        'delay'    => 'Seconds to wait between API calls (default: 1).',
        'dry-run'  => 'Do not call EasyEcom API or update database.',
        'sync-all' => 'Also sync inventory for variations that already have easyecom_product_id.',
    ];

    private int $delaySeconds = 1;
    private bool $dryRun = false;
    private bool $syncAll = false;

    private ProductModel $productModel;
    private EasycomMigrationLogModel $logModel;
    private EasyEcomSkuMappingModel $skuMappingModel;

    public function __construct($logger, $commands)
    {
        parent::__construct($logger, $commands);
        $this->productModel    = new ProductModel();
        $this->logModel        = new EasycomMigrationLogModel();
        $this->skuMappingModel = new EasyEcomSkuMappingModel();
    }

    public function run(array $params): int
    {
        $this->delaySeconds = (int) (CLI::getOption('delay') ?? 1);
        $this->dryRun       = (bool) CLI::getOption('dry-run');
        $this->syncAll      = (bool) CLI::getOption('sync-all');

        if ($this->dryRun) {
            CLI::write('DRY RUN: no API calls or DB updates will be made.', 'yellow');
        }

        $client = service('easyecom');
        if (!$client->isConfigured()) {
            CLI::error('EasyEcom is not configured. Set EASYECOM_* in .env.');
            return EXIT_ERROR;
        }

        $variations = $this->productModel->getVariationsWithoutEasyecomId();
        $total      = count($variations);
        CLI::write("Found {$total} variation(s) without easyecom_product_id.", 'green');

        $success = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($variations as $index => $row) {
            $num   = $index + 1;
            $vId   = (int)$row['id'];
            $pId   = (int)$row['product_id'];
            $sku   = isset($row['sku']) ? trim((string)$row['sku']) : '';

            CLI::write("[{$num}/{$total}] Variation ID={$vId} (Product ID={$pId}) SKU=" . ($sku !== '' ? $sku : '(empty)'), 'cyan');

            if ($sku === '') {
                CLI::error("  Skipping: Empty SKU");
                $failed++;
                continue;
            }

            if ($this->dryRun) {
                CLI::write('  [DRY RUN] Would sync to EasyEcom.', 'yellow');
                $skipped++;
                continue;
            }

            try {
                $eeId = $this->syncWithRetry($client, $vId, $pId, $sku);
                if ($eeId) {
                    $this->updateVariationEasyecomId($vId, $eeId);
                    $this->logSuccess($vId, $sku);
                    $success++;
                    CLI::write("  OK → easyecom_product_id={$eeId}", 'green');

                    // Sync initial inventory
                    $this->syncInventory($client, $vId, $sku);
                } else {
                    $msg = 'No product_id returned from EasyEcom';
                    $this->logFailure($vId, $sku, $msg);
                    $failed++;
                    CLI::error("  Failed: {$msg}");
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->logFailure($vId, $sku, $msg);
                $failed++;
                CLI::error("  Exception: {$msg}");
            }

            if (!$this->dryRun && $num < $total) {
                sleep($this->delaySeconds);
            }
        }

        if ($this->syncAll) {
            $existing = $this->productModel->getVariationsWithEasyecomId();
            $totalExisting = count($existing);
            CLI::newLine();
            CLI::write("Syncing inventory for {$totalExisting} variation(s) with existing easyecom_product_id.", 'green');

            foreach ($existing as $index => $row) {
                $num = $index + 1;
                $vId = (int)$row['id'];
                $sku = trim((string)($row['sku'] ?? ''));

                CLI::write("[EXISTING {$num}/{$totalExisting}] Variation ID={$vId} SKU={$sku}", 'cyan');

                if ($this->dryRun) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->syncInventory($client, $vId, $sku);
                    $success++;
                } catch (\Throwable $e) {
                    CLI::error("  Inventory sync failed: " . $e->getMessage());
                    $failed++;
                }

                if (!$this->dryRun && $num < $totalExisting) {
                    sleep($this->delaySeconds);
                }
            }
        }

        CLI::newLine();
        CLI::write("Done. Success={$success} Failed={$failed} Skipped={$skipped}", 'green');
        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function syncWithRetry($client, int $variationId, int $productId, string $sku, int $retryCount = 0): ?string
    {
        try {
            return $this->syncOneVariation($client, $variationId, $productId, $sku);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'limit exceeded') && $retryCount < 3) {
                $waitTime = ($retryCount + 1) * 5; // 5s, 10s, 15s
                CLI::write("  [429] Limit Exceeded. Waiting {$waitTime}s before retry...", 'yellow');
                sleep($waitTime);
                return $this->syncWithRetry($client, $variationId, $productId, $sku, $retryCount + 1);
            }
            throw $e;
        }
    }

    private function syncOneVariation($client, int $variationId, int $productId, string $sku): ?string
    {
        // Check if SKU already exists in EasyEcom to satisfy idempotency
        $existingId = $this->findExistingProductInEasyecom($client, $sku);
        if ($existingId) {
            return $existingId;
        }

        $product = $this->productModel->getProductDetails($productId);
        $variation = $this->productModel->getProductVariationDetails($variationId);
        
        if (!$product || !$variation) {
            throw new \RuntimeException("Parent product (ID={$productId}) or variation (ID={$variationId}) not found");
        }

        $imageUrl = $this->resolveVariationImageUrl($variationId, $productId);
        $payload = EasyEcomPayloadBuilder::buildCreateVariationPayload($product, $variation, $imageUrl);
        $response = $client->createMasterProduct($payload);

        if (\App\Libraries\EasyEcomClient::isApiFailure($response)) {
            $errorMsg = \App\Libraries\EasyEcomClient::extractErrorMessage($response);
            if (str_contains(strtolower($errorMsg), 'already exists')) {
                return $this->findExistingProductInEasyecom($client, $sku);
            }
            throw new \RuntimeException($errorMsg);
        }

        $eeId = $response['product_id'] ?? $response['ProductId'] ?? $response['data']['product_id'] ?? $response['data']['ProductId'] ?? $response['id'] ?? null;
        return $eeId ? (string)$eeId : null;
    }

    private function resolveVariationImageUrl(int $variationId, int $productId): string
    {
        // 1. Try to get variation-specific image
        $vImages = $this->productModel->getVariationImages($variationId);
        if (!empty($vImages) && !empty($vImages[0]['image'])) {
            return trim((string) $vImages[0]['image']);
        }

        // 2. Fallback to parent product base image
        return $this->resolveProductImageUrl($productId);
    }

    private function resolveProductImageUrl(int $productId): string
    {
        $images = $this->productModel->getProductImageDetailsOrdered($productId);
        if (!empty($images) && !empty($images[0]['image'])) {
            return trim((string) $images[0]['image']);
        }
        return '';
    }

    private function syncInventory($client, int $variationId, string $sku): void
    {
        $variation = $this->productModel->getProductVariationDetails($variationId);
        $quantity = (int)($variation['stock_quantity'] ?? 0);
        $resolvedSku = $this->skuMappingModel->resolveForEasyEcom($sku);

        try {
            $client->syncInventory($resolvedSku, $quantity);
            CLI::write("  Inventory OK: {$quantity}", 'green');
        } catch (\Throwable $e) {
            CLI::write("  Inventory Failed: " . $e->getMessage(), 'yellow');
        }
    }

    private function findExistingProductInEasyecom($client, string $sku): ?string
    {
        try {
            // This is a slow operation, but necessary for backfill safety if SKU might already exist
            $data = $client->getProductMaster(['page' => 1, 'limit' => 250]); 
            $list = $data['data'] ?? $data['products'] ?? $data['items'] ?? $data;
            if (!is_array($list)) return null;
            if (isset($list['data']) && is_array($list['data'])) $list = $list['data'];
            
            foreach ($list as $item) {
                $itemSku = $item['sku'] ?? $item['Sku'] ?? $item['AccountingSKU'] ?? '';
                if (trim((string)$itemSku) === $sku) {
                    return (string)($item['product_id'] ?? $item['ProductId'] ?? $item['id'] ?? null);
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private function updateVariationEasyecomId(int $variationId, string $eeId): void
    {
        $db = \Config\Database::connect();
        $db->table('product_variations')->where('id', $variationId)->update(['easyecom_product_id' => $eeId]);
    }

    private function logSuccess(int $vId, string $sku): void
    {
        $this->logModel->insert([
            'product_id'        => $vId, // Using migration logs for variations as well
            'sku'              => $sku,
            'status'            => 'success_variation',
            'response_message' => null,
        ]);
    }

    private function logFailure(int $vId, string $sku, string $msg): void
    {
        $this->logModel->insert([
            'product_id'        => $vId,
            'sku'              => $sku,
            'status'            => 'failed_variation',
            'response_message' => mb_substr($msg, 0, 65535),
        ]);
    }
}
