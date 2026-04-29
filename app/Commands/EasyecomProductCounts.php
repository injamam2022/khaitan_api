<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\EasyEcomClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Compare local product rows (products table) with EasyEcom master catalog count.
 */
class EasyecomProductCounts extends BaseCommand
{
    protected $group       = 'EasyEcom';
    protected $name        = 'easyecom:product-counts';
    protected $description = 'Show product counts: local DB vs EasyEcom GetProductMaster.';
    protected $usage       = 'easyecom:product-counts [--db-only] [--page-size=200]';

    protected $options = [
        'db-only'   => 'Only print database counts (no EasyEcom API).',
        'page-size' => 'Items per EasyEcom page when paginating (default 200, max 500).',
    ];

    public function run(array $params): int
    {
        $dbOnly   = (bool) CLI::getOption('db-only');
        $pageSize = max(1, min(500, (int) (CLI::getOption('page-size') ?? 200)));

        $db = \Config\Database::connect();

        $totalLocal = (int) $db->table('products')->where('status <>', 'DELETED')->countAllResults();

        $withEasyecom = (int) $db->query(
            "SELECT COUNT(*) AS c FROM products WHERE status <> 'DELETED'
             AND easyecom_product_id IS NOT NULL AND TRIM(COALESCE(easyecom_product_id, '')) <> ''"
        )->getRow()->c;

        $withoutEasyecom = (int) $db->query(
            "SELECT COUNT(*) AS c FROM products WHERE status <> 'DELETED'
             AND (easyecom_product_id IS NULL OR TRIM(COALESCE(easyecom_product_id, '')) = '')"
        )->getRow()->c;

        CLI::write('--- Local database (products table, status <> DELETED) ---', 'yellow');
        CLI::write("Total products:           {$totalLocal}");
        CLI::write("With easyecom_product_id: {$withEasyecom}");
        CLI::write("Without EasyEcom id:      {$withoutEasyecom}");
        CLI::newLine();

        if ($dbOnly) {
            CLI::write('Skipped EasyEcom (--db-only).', 'cyan');
            return EXIT_SUCCESS;
        }

        $client = service('easyecom');
        if (! $client instanceof EasyEcomClient || ! $client->isConfigured()) {
            CLI::error('EasyEcom is not configured. Set EASYECOM_* in .env or use --db-only.');
            return EXIT_ERROR;
        }

        CLI::write('--- EasyEcom (GET /Products/GetProductMaster, paginated) ---', 'yellow');

        try {
            [$count, $reportedTotal] = $this->countEasyecomMasterProducts($client, $pageSize);
        } catch (\Throwable $e) {
            CLI::error('EasyEcom API failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        if ($reportedTotal !== null) {
            CLI::write("API reported total (if present): {$reportedTotal}");
        }
        CLI::write("Counted rows (paginated):         {$count}");
        CLI::newLine();

        if ($reportedTotal !== null && $reportedTotal !== $count) {
            CLI::write('Note: reported total and paginated count differ; trust the API docs or inspect the raw response.', 'yellow');
        }

        CLI::write('--- Comparison ---', 'yellow');
        CLI::write('Local total vs EasyEcom master rows are often not 1:1 (variations, manual EE SKUs, deleted local rows, etc.).');
        CLI::write("Local total: {$totalLocal} | EasyEcom rows counted: {$count}");

        return EXIT_SUCCESS;
    }

    /**
     * @return array{0: int, 1: int|null} [paginated row count, API total if JSON provided it]
     */
    private function countEasyecomMasterProducts(EasyEcomClient $client, int $pageSize): array
    {
        $reportedTotal = null;
        $sum           = 0;
        $page          = 1;
        $maxPages      = 10000;

        while ($page <= $maxPages) {
            $response = $client->getProductMaster(['page' => $page, 'limit' => $pageSize]);

            if (isset($response['_status']) && (int) $response['_status'] >= 400) {
                throw new \RuntimeException('HTTP ' . ($response['_status'] ?? '?') . ' from GetProductMaster');
            }

            if ($page === 1) {
                $reportedTotal = $this->extractReportedTotal($response);
            }

            $rows = $this->extractProductRows($response);
            $n    = count($rows);
            $sum += $n;

            if ($n < $pageSize) {
                break;
            }
            $page++;
        }

        if ($page > $maxPages) {
            CLI::write('[WARN] Stopped at max pages (' . $maxPages . '); total may be incomplete.', 'yellow');
        }

        return [$sum, $reportedTotal];
    }

    private function extractReportedTotal(array $response): ?int
    {
        $keys = ['total', 'Total', 'total_count', 'TotalCount', 'total_records', 'recordCount', 'recordsTotal', 'TotalRecords'];
        foreach ($keys as $k) {
            if (isset($response[$k]) && is_numeric($response[$k])) {
                return (int) $response[$k];
            }
        }
        foreach (['pagination', 'Pagination', 'meta', 'Meta'] as $blockKey) {
            $nested = $response[$blockKey] ?? null;
            if (! is_array($nested)) {
                continue;
            }
            foreach (['total', 'Total', 'total_records', 'record_count', 'TotalCount'] as $k) {
                if (isset($nested[$k]) && is_numeric($nested[$k])) {
                    return (int) $nested[$k];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractProductRows(array $data): array
    {
        $list = $data['data'] ?? $data['products'] ?? $data['items'] ?? $data['Data'] ?? $data;
        if (! is_array($list)) {
            return [];
        }
        if (isset($list['data']) && is_array($list['data'])) {
            $list = $list['data'];
        }
        if ($list === []) {
            return [];
        }
        if (array_keys($list) === range(0, count($list) - 1)) {
            return $list;
        }
        // Single product object
        if (isset($list['Sku']) || isset($list['sku']) || isset($list['ProductId']) || isset($list['product_id'])) {
            return [$list];
        }

        return [];
    }
}
