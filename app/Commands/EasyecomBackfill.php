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
 * Safe backfill script: sync products where easyecom_product_id IS NULL.
 *
 * Uses DB-cached token via refactored EasyEcomClient (no 429 retry loops).
 * Rate limiting is handled via --delay between products.
 *
 * - Queries products where easyecom_product_id IS NULL
 * - Validates required fields (SKU, name, category)
 * - Calls EasyEcom CreateMasterProduct (or fetches existing ID if product already exists)
 * - Updates products.easyecom_product_id on success; logs failures to easycom_migration_logs
 * - Pushes stock after product sync
 * - Does not affect the new queue-based product creation flow.
 */
class EasyecomBackfill extends BaseCommand
{
    protected $group       = 'EasyEcom';
    protected $name        = 'easyecom:backfill';
    protected $description = 'Backfill EasyEcom product IDs for existing products and optionally sync name/inventory.';
    protected $usage       = 'easyecom:backfill [--delay=1] [--dry-run] [--sync-all]';

    protected $options = [
        'delay'    => 'Seconds to wait between API calls (default: 1).',
        'dry-run'  => 'Do not call EasyEcom API or update database.',
        'sync-all' => 'Also sync name & inventory for products that already have easyecom_product_id.',
    ];

    /** @var int Delay in seconds between API calls (rate limiting). */
    private int $delaySeconds = 1;

    /** @var bool If true, do not call API or update DB. */
    private bool $dryRun = false;

    /** @var bool If true, also sync name & inventory for products that already have easyecom_product_id. */
    private bool $syncAll = false;

    /** @var ProductModel */
    private ProductModel $productModel;

    /** @var EasycomMigrationLogModel */
    private EasycomMigrationLogModel $logModel;

    /** @var EasyEcomSkuMappingModel */
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

        if ($this->syncAll) {
            CLI::write('SYNC ALL: will also sync product name and inventory for products that already have EasyEcom IDs.', 'yellow');
        }

        $client = service('easyecom');
        if (! $client->isConfigured()) {
            CLI::error('EasyEcom is not configured. Set EASYECOM_* in .env.');
            return EXIT_ERROR;
        }

        $products = $this->productModel->getProductsWithoutEasyecomId();
        $total    = count($products);
        CLI::write("Found {$total} product(s) without easyecom_product_id.", 'green');

        $success = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($products as $index => $row) {
            $num   = $index + 1;
            $pId   = (int) $row['id'];
            $sku   = isset($row['sku_number']) ? trim((string) $row['sku_number']) : '';

            CLI::write("[{$num}/{$total}] Product ID={$pId} SKU=" . ($sku !== '' ? $sku : '(empty)'), 'cyan');

            // Validate required fields
            $validationError = $this->validateProductForEasyecom($pId, $row);
            if ($validationError !== null) {
                CLI::error("  Validation failed: {$validationError}");
                $this->logFailure($pId, $sku, $validationError);
                $failed++;
                continue;
            }

            if ($this->dryRun) {
                CLI::write('  [DRY RUN] Would sync to EasyEcom.', 'yellow');
                $skipped++;
                continue;
            }

            try {
                $eeProductId = $this->syncOneProduct($client, $pId, $sku);
                if ($eeProductId !== null && $eeProductId !== '') {
                    $this->updateProductEasyecomId($pId, (string) $eeProductId);
                    $this->logSuccess($pId, $sku);
                    $success++;
                    CLI::write("  OK → easyecom_product_id={$eeProductId}", 'green');

                    // After backfilling EasyEcom product ID, also update master product name/details and inventory.
                    $this->syncProductNameAndInventory($client, $pId, $sku);
                } else {
                    $msg = 'No product_id returned from EasyEcom';
                    $this->logFailure($pId, $sku, $msg);
                    $failed++;
                    CLI::error("  Failed: {$msg}");
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->logFailure($pId, $sku, $msg);
                $failed++;
                CLI::error("  Exception: {$msg}");
                log_message('error', 'EasyecomBackfill product_id=' . $pId . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }

            // Rate limiting: delay before next API call
            if (! $this->dryRun && $num < $total) {
                sleep($this->delaySeconds);
            }
        }

        // Optional second pass: sync name & inventory for existing EasyEcom IDs
        if ($this->syncAll) {
            $existing = $this->productModel->getProductsWithEasyecomId();
            $totalExisting = count($existing);
            CLI::newLine();
            CLI::write("Syncing {$totalExisting} product(s) with existing easyecom_product_id for name/inventory.", 'green');

            foreach ($existing as $index => $row) {
                $num   = $index + 1;
                $pId   = (int) $row['id'];
                $sku   = isset($row['sku_number']) ? trim((string) $row['sku_number']) : '';

                CLI::write("[EXISTING {$num}/{$totalExisting}] Product ID={$pId} SKU=" . ($sku !== '' ? $sku : '(empty)'), 'cyan');

                if ($this->dryRun) {
                    CLI::write('  [DRY RUN] Would update name & inventory on EasyEcom.', 'yellow');
                    $skipped++;
                    continue;
                }

                try {
                    $this->syncProductNameAndInventory($client, $pId, $sku);
                    $success++;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    $this->logFailure($pId, $sku, $msg);
                    $failed++;
                    CLI::write('  [WARN] Exception during existing product name/inventory sync: ' . $msg, 'yellow');
                    log_message('error', 'EasyecomBackfill existing product sync product_id=' . $pId . ': ' . $e->getMessage());
                }

                if (! $this->dryRun && $num < $totalExisting) {
                    sleep($this->delaySeconds);
                }
            }
        }

        CLI::newLine();
        CLI::write("Done. Success={$success} Failed={$failed} Skipped={$skipped}", 'green');
        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * Validate required fields for EasyEcom. Returns error message or null if valid.
     */
    private function validateProductForEasyecom(int $productId, array $row): ?string
    {
        $sku = isset($row['sku_number']) ? trim((string) $row['sku_number']) : '';
        if ($sku === '') {
            return 'SKU is required and cannot be empty';
        }

        $name = isset($row['product_name']) ? trim((string) $row['product_name']) : '';
        if ($name === '') {
            return 'Product name is required';
        }

        // Price is not required for backfill; we send 0 if not set (EasyEcom may accept or reject)
        $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
        if ($categoryId <= 0) {
            return 'Category is required';
        }

        // Ensure SKU is unique in our products table (for this migration we allow same SKU only if already in EasyEcom - idempotent)
        $db = \Config\Database::connect();
        $duplicate = $db->table('products')
            ->where('sku_number', $sku)
            ->where('id !=', $productId)
            ->where('easyecom_product_id IS NOT NULL', null, false)
            ->get()
            ->getRowArray();
        if (! empty($duplicate)) {
            return 'Another product (ID=' . ($duplicate['id'] ?? '') . ') already has this SKU and an EasyEcom ID; ensure SKU is unique or resolve manually';
        }

        return null;
    }

    /**
     * Build EasyEcom CreateMasterProduct payload for one product.
     * Delegates to shared EasyEcomPayloadBuilder.
     */
    private function buildEasyecomPayload(int $productId): array
    {
        $product = $this->productModel->getProductDetails($productId);
        if (! $product) {
            throw new \RuntimeException('Product not found: ' . $productId);
        }

        $description = '';
        $descriptions = $this->productModel->getAllProductDescriptions($productId);
        foreach ($descriptions as $d) {
            if (! empty($d['content'])) {
                $description = trim((string) $d['content']);
                if (($d['description_type'] ?? '') === 'long') {
                    break;
                }
            }
        }
        if ($description === '' && $descriptions !== []) {
            $description = trim((string) ($descriptions[0]['content'] ?? ''));
        }

        $imageUrl = '';
        $images = $this->productModel->getProductImageDetailsOrdered($productId);
        if (! empty($images) && ! empty($images[0]['image'])) {
            $imageUrl = trim((string) $images[0]['image']);
        }

        return EasyEcomPayloadBuilder::buildCreateProductPayload($product, $description, $imageUrl);
    }

    /**
     * Sync one product to EasyEcom: create or (if already exists) fetch ID. Idempotent.
     *
     * @return string|null EasyEcom product ID or null on failure
     */
    private function syncOneProduct($client, int $productId, string $sku): ?string
    {
        // Idempotency: try to find existing product in EasyEcom by SKU first
        $existingId = $this->findExistingProductInEasyecom($client, $sku);
        if ($existingId !== null && $existingId !== '') {
            return $existingId;
        }

        $payload = $this->buildEasyecomPayload($productId);
        $response = $client->createMasterProduct($payload);

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            $errorLower = strtolower($errorMsg);

            // Idempotency: if API says product/SKU already exists, try to fetch and return that ID
            if (str_contains($errorLower, 'already exists') || str_contains($errorLower, 'duplicate') || str_contains($errorLower, 'sku')) {
                $existingId = $this->findExistingProductInEasyecom($client, $sku);
                if ($existingId !== null && $existingId !== '') {
                    return $existingId;
                }
            }
            throw new \RuntimeException($errorMsg);
        }

        $eeProductId = $response['product_id'] ?? $response['ProductId'] ?? $response['data']['product_id'] ?? $response['data']['ProductId'] ?? $response['id'] ?? null;
        return $eeProductId !== null && $eeProductId !== '' ? (string) $eeProductId : null;
    }

    /**
     * After backfilling the EasyEcom product ID, update master product name/details and inventory quantity.
     * - Uses UpdateMasterProduct so EasyEcom name/model/price match our DB.
     * - Uses updateInventory so EasyEcom stock matches our stock_quantity.
     * Any failures are logged to CLI and CI logs but do not stop the migration.
     *
     * @param mixed  $client     EasyEcomClient instance from service('easyecom')
     * @param int    $productId  Local product ID
     * @param string $sku        Local SKU (sku_number)
     */
    private function syncProductNameAndInventory($client, int $productId, string $sku): void
    {
        $product = $this->productModel->getProductDetails($productId);
        if (! $product) {
            CLI::write('  [WARN] Could not load product details for name/inventory sync.', 'yellow');
            return;
        }

        $eeId = isset($product['easyecom_product_id']) ? trim((string) $product['easyecom_product_id']) : '';
        if ($eeId === '') {
            CLI::write('  [WARN] easyecom_product_id missing after backfill; skipping name/inventory sync.', 'yellow');
            return;
        }

        // 1) Update master product name/details (non-fatal on failure)
        try {
            $payload = EasyEcomPayloadBuilder::buildUpdateProductPayload($eeId, $product);
            $resp = $client->updateMasterProduct($payload);

            if (EasyEcomClient::isApiFailure($resp)) {
                $msg = EasyEcomClient::extractErrorMessage($resp);
                CLI::write('  [WARN] UpdateMasterProduct failed for product ID=' . $productId . ': ' . $msg, 'yellow');
                log_message('error', 'EasyecomBackfill UpdateMasterProduct failed product_id=' . $productId . ' response=' . json_encode($resp));
            } else {
                CLI::write('  Updated EasyEcom master product name/details.', 'green');
            }
        } catch (\Throwable $e) {
            CLI::write('  [WARN] Exception during UpdateMasterProduct: ' . $e->getMessage(), 'yellow');
            log_message('error', 'EasyecomBackfill UpdateMasterProduct exception product_id=' . $productId . ': ' . $e->getMessage());
        }

        // 2) Update inventory quantity (non-fatal on failure)
        try {
            $stockQty = isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : 0;

            $internalSku = trim((string) $sku);
            if ($internalSku === '') {
                CLI::write('  [INFO] Inventory sync skipped — no SKU for product ID=' . $productId, 'yellow');
                return;
            }

            $resolvedSku = $this->skuMappingModel->resolveForEasyEcom($internalSku);

            $client->updateInventory($resolvedSku, $stockQty);
            CLI::write('  Updated EasyEcom inventory sku=' . $resolvedSku . ' quantity=' . $stockQty, 'green');
        } catch (\Throwable $e) {
            CLI::write('  [WARN] Exception during inventory update: ' . $e->getMessage(), 'yellow');
            log_message('error', 'EasyecomBackfill inventory update exception product_id=' . $productId . ' sku=' . $sku . ': ' . $e->getMessage());
        }
    }

    /**
     * Try to find EasyEcom product ID by SKU via GetProductMaster (idempotency).
     *
     * @return string|null EasyEcom product ID or null if not found
     */
    private function findExistingProductInEasyecom($client, string $sku): ?string
    {
        try {
            $data = $client->getProductMaster(['page' => 1, 'limit' => 500]);
            $list = $data['data'] ?? $data['products'] ?? $data['items'] ?? $data;
            if (! is_array($list)) {
                return null;
            }
            // Handle paginated response: sometimes list is under a key
            if (isset($list['data']) && is_array($list['data'])) {
                $list = $list['data'];
            }
            foreach ($list as $item) {
                $itemSku = $item['sku'] ?? $item['Sku'] ?? $item['sku_number'] ?? $item['AccountingSKU'] ?? '';
                if (trim((string) $itemSku) === $sku) {
                    $id = $item['product_id'] ?? $item['ProductId'] ?? $item['id'] ?? $item['Id'] ?? null;
                    if ($id !== null && $id !== '') {
                        return (string) $id;
                    }
                }
            }
            // If GetProductMaster is paginated, we only check first page; could extend to loop pages
        } catch (\Throwable $e) {
            log_message('debug', 'EasyecomBackfill findExistingProductInEasyecom: ' . $e->getMessage());
        }
        return null;
    }

    private function updateProductEasyecomId(int $productId, string $easyecomProductId): void
    {
        $db = \Config\Database::connect();
        $db->transStart();
        try {
            $db->table('products')
                ->where('id', $productId)
                ->update(['easyecom_product_id' => $easyecomProductId]);
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new \RuntimeException('Database transaction failed while updating easyecom_product_id');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    private function logSuccess(int $productId, string $sku): void
    {
        $this->logModel->insert([
            'product_id'        => $productId,
            'sku'              => $sku,
            'status'            => 'success',
            'response_message' => null,
        ]);
    }

    private function logFailure(int $productId, string $sku, string $responseMessage): void
    {
        $this->logModel->insert([
            'product_id'        => $productId,
            'sku'              => $sku,
            'status'            => 'failed',
            'response_message' => mb_substr($responseMessage, 0, 65535),
        ]);
    }
}
