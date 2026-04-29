<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\EasyEcomClient;
use App\Libraries\EasyEcomPayloadBuilder;
use App\Models\ProductModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Command to sync master product/variation details (Name, SKU, Price, etc.) to EasyEcom.
 * This is useful for "backfilling" missing information on products that already have an EasyEcom ID.
 * 
 * - Loops through all products with easyecom_product_id and calls UpdateMasterProduct.
 * - Loops through all variations with easyecom_product_id and calls UpdateMasterProduct.
 */
class EasyecomMasterSync extends BaseCommand
{
    protected $group       = 'EasyEcom';
    protected $name        = 'easyecom:master-sync';
    protected $description = 'Sync product and variation details (Price, Name, SKU) to EasyEcom.';
    protected $usage       = 'easyecom:master-sync [--delay=1] [--dry-run] [--type=all]';

    protected $options = [
        'delay'   => 'Seconds to wait between API calls (default: 1).',
        'dry-run' => 'Do not call EasyEcom API.',
        'type'    => 'What to sync: all, product, variation (default: all).',
    ];

    private int $delaySeconds = 1;
    private bool $dryRun = false;
    private string $type = 'all';

    private ProductModel $productModel;

    public function __construct($logger, $commands)
    {
        parent::__construct($logger, $commands);
        $this->productModel = new ProductModel();
    }

    public function run(array $params): int
    {
        $this->delaySeconds = (int) (CLI::getOption('delay') ?? 1);
        $this->dryRun       = (bool) CLI::getOption('dry-run');
        $this->type         = (string) (CLI::getOption('type') ?? 'all');

        if ($this->dryRun) {
            CLI::write('DRY RUN: no API calls will be made.', 'yellow');
        }

        $client = service('easyecom');
        if (!$client->isConfigured()) {
            CLI::error('EasyEcom is not configured.');
            return EXIT_ERROR;
        }

        $success = 0;
        $failed  = 0;

        // 1. Sync Products
        if (in_array($this->type, ['all', 'product'])) {
            $products = $this->productModel->getProductsWithEasyecomId();
            $total = count($products);
            CLI::write("Syncing details for {$total} product(s).", 'green');
            
            foreach ($products as $idx => $row) {
                $num = $idx + 1;
                $pId = (int)$row['id'];
                $eeId = trim((string)($row['easyecom_product_id'] ?? ''));
                $sku = trim((string)($row['sku_number'] ?? ''));

                CLI::write("[{$num}/{$total}] Product ID={$pId} SKU={$sku}", 'cyan');

                if ($eeId === '') {
                    CLI::error("  Skipping: No EasyEcom ID");
                    continue;
                }

                if ($this->dryRun) {
                    $success++;
                    continue;
                }

                try {
                    $imageUrl = $this->resolveProductImageUrl($pId);
                    $payload = EasyEcomPayloadBuilder::buildUpdateProductPayload($eeId, $row, $imageUrl);
                    $response = $this->syncWithRetry($client, 'updateMasterProduct', $payload);
                    
                    if (EasyEcomClient::isApiFailure($response)) {
                        $failed++;
                        CLI::error("  Failed: " . EasyEcomClient::extractErrorMessage($response));
                    } else {
                        $success++;
                        CLI::write("  OK", 'green');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    CLI::error("  Exception: " . $e->getMessage());
                }

                if ($num < $total) sleep($this->delaySeconds);
            }
        }

        // 2. Sync Variations
        if (in_array($this->type, ['all', 'variation'])) {
            $variations = $this->productModel->getVariationsWithEasyecomId();
            $total = count($variations);
            CLI::write("Syncing details for {$total} variation(s).", 'green');

            foreach ($variations as $idx => $row) {
                $num = $idx + 1;
                $vId = (int)$row['id'];
                $pId = (int)$row['product_id'];
                $eeId = trim((string)($row['easyecom_product_id'] ?? ''));
                $sku = trim((string)($row['sku'] ?? ''));

                CLI::write("[{$num}/{$total}] Variation ID={$vId} SKU={$sku}", 'cyan');

                if ($eeId === '') {
                    CLI::error("  Skipping: No EasyEcom ID");
                    continue;
                }

                if ($this->dryRun) {
                    $success++;
                    continue;
                }

                try {
                    $product = $this->productModel->getProductDetails($pId);
                    if (!$product) {
                        CLI::error("  Parent product not found");
                        $failed++;
                        continue;
                    }

                    $imageUrl = $this->resolveVariationImageUrl($vId, $pId);
                    $payload = EasyEcomPayloadBuilder::buildUpdateVariationPayload($eeId, $product, $row, $imageUrl);
                    $response = $this->syncWithRetry($client, 'updateMasterProduct', $payload);

                    if (EasyEcomClient::isApiFailure($response)) {
                        $failed++;
                        CLI::error("  Failed: " . EasyEcomClient::extractErrorMessage($response));
                    } else {
                        $success++;
                        CLI::write("  OK", 'green');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    CLI::error("  Exception: " . $e->getMessage());
                }

                if ($num < $total) sleep($this->delaySeconds);
            }
        }

        CLI::newLine();
        CLI::write("Done. Success={$success} Failed={$failed}", 'green');
        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
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

    private function syncWithRetry($client, string $method, array $payload, int $retryCount = 0): array
    {
        try {
            return $client->$method($payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'limit exceeded') && $retryCount < 3) {
                $waitTime = ($retryCount + 1) * 5;
                CLI::write("  [429] Limit Exceeded. Waiting {$waitTime}s...", 'yellow');
                sleep($waitTime);
                return $this->syncWithRetry($client, $method, $payload, $retryCount + 1);
            }
            throw $e;
        }
    }
}
