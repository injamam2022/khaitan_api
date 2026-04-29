<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ProductModel;
use App\Models\EasyEcomSkuMappingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Safe stock sync: Push our database stock quantities to EasyEcom.
 *
 * Addresses legacy products created before EasyEcom integration where stock
 * remains 0 in EasyEcom while our DB has valid quantities.
 *
 * - Reads all stockable SKUs (products + variations) from our DB
 * - Resolves SKU mapping for EasyEcom (internal → EasyEcom SKU)
 * - Uses Virtual Inventory API (POST /updateVirtualInventoryAPI); fallback to single syncVirtualInventory per SKU on batch failure.
 * - No impact on existing product sync or order flows
 * - Backward compatible with previously created products
 */
class EasyecomStockSync extends BaseCommand
{
    protected $group       = 'EasyEcom';
    protected $name        = 'easyecom:stock-sync';
    protected $description = 'Push database stock quantities to EasyEcom (legacy product inventory sync).';
    protected $usage       = 'easyecom:stock-sync [--dry-run] [--batch-size=50] [--delay=1]';

    protected $options = [
        'dry-run'     => 'Do not call EasyEcom API; only show what would be synced.',
        'batch-size'  => 'Number of SKUs per bulk API call (default: 50).',
        'delay'       => 'Seconds to wait between bulk API calls (default: 1).',
    ];

    private bool $dryRun = false;
    private int $batchSize = 50;
    private int $delaySeconds = 1;

    private ProductModel $productModel;
    private EasyEcomSkuMappingModel $skuMappingModel;

    public function __construct($logger, $commands)
    {
        parent::__construct($logger, $commands);
        $this->productModel    = new ProductModel();
        $this->skuMappingModel = new EasyEcomSkuMappingModel();
    }

    public function run(array $params): int
    {
        $this->dryRun      = (bool) CLI::getOption('dry-run');
        $this->batchSize   = max(1, min(100, (int) (CLI::getOption('batch-size') ?? 50)));
        $this->delaySeconds = max(0, (int) (CLI::getOption('delay') ?? 1));

        if ($this->dryRun) {
            CLI::write('DRY RUN: no API calls will be made.', 'yellow');
        }

        $client = service('easyecom');
        if (! $client instanceof \App\Libraries\EasyEcomClient || ! $client->isConfigured()) {
            CLI::error('EasyEcom is not configured. Set EASYECOM_* in .env.');
            return EXIT_ERROR;
        }

        $items = $this->productModel->getAllStockableSkusWithQuantity();
        $total = count($items);

        if ($total === 0) {
            CLI::write('No stockable SKUs found in database.', 'yellow');
            return EXIT_SUCCESS;
        }

        CLI::write("Found {$total} stockable SKU(s) in database.", 'green');

        // Resolve SKUs for EasyEcom and build payload
        $payload = [];
        foreach ($items as $item) {
            $internalSku = $item['sku'];
            $resolvedSku = $this->skuMappingModel->resolveForEasyEcom($internalSku);
            $payload[] = [
                'sku'      => $resolvedSku,
                'quantity' => $item['quantity'],
            ];
        }

        $success = 0;
        $failed  = 0;

        // Note: Currently/inventory API typically expects single SKU updates as per requirement.
        // We will loop through SKUs to ensure reliable updates via the base inventory API.
        foreach ($payload as $idx => $item) {
            $num = $idx + 1;
            CLI::write("Processing SKU {$num}/{$total}: {$item['sku']} (Qty: {$item['quantity']})", 'cyan');

            if ($this->dryRun) {
                CLI::write("  [DRY RUN] Would sync sku={$item['sku']} quantity={$item['quantity']}", 'yellow');
                $success++;
                continue;
            }

            try {
                $client->syncInventory($item['sku'], (int)$item['quantity']);
                $success++;
                CLI::write("  OK – synced", 'green');
            } catch (\Throwable $e) {
                $failed++;
                CLI::error("  Failed: " . $e->getMessage());
                log_message('error', 'EasyecomStockSync Error for SKU ' . $item['sku'] . ': ' . $e->getMessage());
            }

            if (! $this->dryRun && $idx < $total - 1 && $this->delaySeconds > 0) {
                usleep((int)($this->delaySeconds * 1000000));
            }
        }

        CLI::newLine();
        CLI::write("Done. Success={$success} Failed={$failed}", $failed > 0 ? 'yellow' : 'green');
        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
