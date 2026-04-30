<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Libraries\EasyEcomSyncService;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Products extends BaseController
{
    use ResponseTrait;

    protected $productModel;
    /**
     * Cache resolved primary image filenames per product ID.
     *
     * @var array<int, string|null>
     */
    private array $primaryImageResolutionCache = [];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->productModel = new ProductModel();
        helper(['api_helper', 'stock_helper', 'pricing_helper']);
    }

    /**
     * Apply image soft-delete flags for category records.
     */
    private function sanitizeCategoryImages(array $category): array
    {
        $logoStatus = strtoupper((string)($category['logo_status'] ?? 'ACTIVE'));
        $bannerStatus = strtoupper((string)($category['banner_status'] ?? 'ACTIVE'));

        if ($logoStatus === 'DELETED') {
            $category['logo'] = null;
        }
        if ($bannerStatus === 'DELETED') {
            $category['home_static_image'] = null;
        }

        return $category;
    }

    /**
     * Process uploaded brochure file(s) and save to assets/brochures/.
     * Returns array of brochure entries (pdf_url, file_name, file_size) for bulkUpdateProductBrochures.
     * Upload path: FCPATH . assets/brochures/ (web-accessible; API serves as assetsBaseURL . 'brochures/').
     */
    private function processBrochureUploads(int $productId): array
    {
        $files = $this->request->getFiles();
        if (empty($files['brochure'])) {
            return [];
        }
        $brochureDir = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brochures' . DIRECTORY_SEPARATOR;
        if (!is_dir($brochureDir)) {
            if (!@mkdir($brochureDir, 0755, true) || !is_dir($brochureDir)) {
                log_message('error', 'Products::processBrochureUploads - Failed to create directory: ' . $brochureDir);
                return [];
            }
        }
        $brochureFiles = $files['brochure'];
        if (!is_array($brochureFiles)) {
            $brochureFiles = [$brochureFiles];
        }
        $allowedMimes = ['application/pdf' => true];
        $entries = [];
        foreach ($brochureFiles as $idx => $file) {
            if (!$file->isValid() || $file->hasMoved()) {
                continue;
            }
            $mime = $file->getClientMimeType();
            if (!isset($allowedMimes[$mime])) {
                log_message('warning', 'Products::processBrochureUploads - Rejected non-PDF file: ' . ($file->getClientName() ?? 'unknown'));
                continue;
            }
            $ext = $file->getClientExtension() ?: 'pdf';
            if (strtolower($ext) !== 'pdf') {
                $ext = 'pdf';
            }
            $newName = 'brochure_' . $productId . '_' . time() . '_' . $idx . '.' . $ext;
            if ($file->move($brochureDir, $newName)) {
                $entries[] = [
                    'pdf_url' => base_url('assets/brochures/' . $newName),
                    'file_name' => $file->getClientName(),
                    'file_size' => $file->getSize(),
                    'thumbnail_url' => null,
                    'alt_text' => null,
                ];
            } else {
                log_message('error', 'Products::processBrochureUploads - Move failed: ' . $brochureDir . $newName);
            }
        }
        return $entries;
    }

    /**
     * Resolve API primary image to an existing file when DB value is stale.
     */
    private function resolvePrimaryImageForResponse(int $productId, ?string $primaryImage): ?string
    {
        if ($productId <= 0) {
            return $primaryImage;
        }

        if (array_key_exists($productId, $this->primaryImageResolutionCache)) {
            return $this->primaryImageResolutionCache[$productId];
        }

        $resolved = $primaryImage;
        $imagesDir = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'productimages' . DIRECTORY_SEPARATOR;

        $candidate = is_string($primaryImage) ? trim($primaryImage) : '';
        if ($candidate !== '') {
            // If DB value exists on disk, keep it as-is.
            if (is_file($imagesDir . $candidate)) {
                $this->primaryImageResolutionCache[$productId] = $candidate;
                return $candidate;
            }

            // Extension fallback (jpg/jpeg/png -> webp and vice versa).
            $extCandidates = [];
            if (preg_match('/^(.*)\.(jpg|jpeg|png|webp)$/i', $candidate, $parts) === 1) {
                $base = $parts[1];
                $currentExt = strtolower($parts[2]);
                $alternates = ['webp', 'jpg', 'jpeg', 'png'];
                foreach ($alternates as $ext) {
                    if ($ext === $currentExt) {
                        continue;
                    }
                    $extCandidates[] = $base . '.' . $ext;
                }
            }

            foreach ($extCandidates as $altName) {
                if (is_file($imagesDir . $altName)) {
                    $resolved = $altName;
                    $this->primaryImageResolutionCache[$productId] = $resolved;
                    return $resolved;
                }
            }
        }

        // Last resort: pick best existing file for this product ID.
        $resolved = $this->findBestExistingProductImage($productId);
        $this->primaryImageResolutionCache[$productId] = $resolved;
        return $resolved;
    }

    /**
     * Find best existing product image by filename convention:
     * product_{productId}_{timestamp}_{index}.{ext}
     */
    private function findBestExistingProductImage(int $productId): ?string
    {
        $imagesDir = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'productimages' . DIRECTORY_SEPARATOR;
        $pattern = $imagesDir . 'product_' . $productId . '_*.*';
        $matches = glob($pattern);
        if (empty($matches) || !is_array($matches)) {
            return null;
        }

        $bestFile = null;
        $bestTimestamp = -1;
        $bestIndex = PHP_INT_MAX;

        foreach ($matches as $fullPath) {
            $name = basename($fullPath);
            if (preg_match('/^product_' . preg_quote((string)$productId, '/') . '_(\d+)_(\d+)\.(?:jpg|jpeg|png|gif|webp)$/i', $name, $parts) !== 1) {
                continue;
            }

            $timestamp = (int)$parts[1];
            $index = (int)$parts[2];

            if ($timestamp > $bestTimestamp || ($timestamp === $bestTimestamp && $index < $bestIndex)) {
                $bestTimestamp = $timestamp;
                $bestIndex = $index;
                $bestFile = $name;
            }
        }

        if ($bestFile !== null) {
            return $bestFile;
        }

        return basename($matches[0]);
    }

    public function index()
    {
        try {
            // Get status filter from query parameter (optional)
            // If status='DELETED', return deleted products (for trash)
            // If status is other value, filter by that status
            // If no status, return all non-deleted products (default)
            $status = $this->request->getGet('status') ?: null;
            
            $product_list = $this->productModel->getProductList($status);
            
            // Ensure product_list is an array
            if (!is_array($product_list)) {
                $product_list = [];
            }
            
            // Debug: Check if images exist for products
            foreach ($product_list as &$product) {
                if (empty($product['primary_image'])) {
                    // Check if images actually exist in database (getProductImageDetails returns ordered by display_order)
                    $images = $this->productModel->getProductImageDetails($product['id']);
                    if (!empty($images)) {
                        log_message('debug', 'Product ' . $product['id'] . ' has ' . count($images) . ' images but query returned null. First image: ' . $images[0]['image']);
                        $product['primary_image'] = $images[0]['image'];
                    } else {
                        log_message('debug', 'Product ' . $product['id'] . ' has no images in database');
                    }
                }
            }
            
            return json_success($product_list);
        } catch (\Exception $e) {
            log_message('error', 'Product list error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return json_error('Error loading products: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Storefront products listing endpoint.
     * Compatible with UI call: POST /api/products/lists/v2
     */
    public function listsV2()
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $page = max(1, (int)($payload['page'] ?? 1));
            $limitRaw = (int)($payload['limit'] ?? 20);
            $limit = $limitRaw > 0 ? min($limitRaw, 100000) : 20;
            $offset = ($page - 1) * $limit;

            $sort = strtolower(trim((string)($payload['sort'] ?? '')));
            $searchTerms = trim((string)($payload['searchTerms'] ?? ''));

            $priceExpr = "COALESCE(NULLIF(P.final_price, 0), NULLIF(P.sale_price, 0), NULLIF(P.product_price, 0), NULLIF(P.mrp, 0), 0)";

            $builder = $this->productModel->db->table('products AS P');
            $builder->select("
                P.id,
                P.product_name,
                P.slug,
                P.short_description,
                {$priceExpr} AS min_price,
                (SELECT PI.image
                 FROM product_image AS PI
                 WHERE PI.product_id = CAST(P.id AS UNSIGNED)
                   AND PI.status <> 'DELETED'
                 ORDER BY PI.display_order ASC, PI.id ASC
                 LIMIT 1) AS primary_image,
                C.id AS category_id,
                C.category AS category_name,
                C.categorykey AS category_slug,
                SC.id AS subcategory_id,
                SC.category AS subcategory_name,
                SC.categorykey AS subcategory_slug,
                SC.home_static_image AS subcategory_banner_image
            ");
            $builder->join('product_category AS SC', 'SC.id = P.category_id AND SC.status <> \'DELETED\'', 'left');
            $builder->join(
                'product_category AS C',
                'C.id = (CASE WHEN SC.parent_id IS NULL OR SC.parent_id = 0 THEN SC.id ELSE SC.parent_id END) AND C.status <> \'DELETED\'',
                'left'
            );
            $builder->where('P.status <>', 'DELETED');

            if ($searchTerms !== '') {
                $builder->groupStart()
                    ->like('P.product_name', $searchTerms)
                    ->orLike('P.slug', $searchTerms)
                    ->orLike('P.short_description', $searchTerms)
                    ->groupEnd();
            }

            if ($sort === 'price_desc') {
                $builder->orderBy("{$priceExpr}", 'DESC', false);
            } elseif ($sort === 'price_asc') {
                $builder->orderBy("{$priceExpr}", 'ASC', false);
            } else {
                $builder->orderBy('P.home_display_order', 'ASC');
                $builder->orderBy('P.id', 'ASC');
            }

            $total = (int)$builder->countAllResults(false);
            $rows = $builder->limit($limit, $offset)->get()->getResultArray();

            $products = array_map(function (array $row): array {
                $productId = (int)($row['id'] ?? 0);
                $resolvedPrimaryImage = $this->resolvePrimaryImageForResponse($productId, $row['primary_image'] ?? null);
                return [
                    'id' => $productId,
                    'product_name' => (string)($row['product_name'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'short_description' => (string)($row['short_description'] ?? ''),
                    'min_price' => (float)($row['min_price'] ?? 0),
                    'primary_image' => $resolvedPrimaryImage,
                    'category' => [
                        'id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
                        'name' => $row['category_name'] ?? null,
                        'slug' => $row['category_slug'] ?? null,
                    ],
                    'subcategory' => [
                        'id' => isset($row['subcategory_id']) ? (int)$row['subcategory_id'] : null,
                        'name' => $row['subcategory_name'] ?? null,
                        'slug' => $row['subcategory_slug'] ?? null,
                        'banner_image' => $row['subcategory_banner_image'] ?? null,
                    ],
                ];
            }, $rows);

            return json_success([
                'products' => $products,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / max(1, $limit)),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Products::listsV2 error: ' . $e->getMessage());
            log_message('error', 'Products::listsV2 trace: ' . $e->getTraceAsString());
            return json_error('Failed to fetch products', 500);
        }
    }

    /**
     * Storefront products filter endpoint.
     * Compatible with UI call: POST /api/products/filter/v2
     */
    public function filterV2()
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $filters = isset($payload['filters']) && is_array($payload['filters']) ? $payload['filters'] : [];

            $searchTerms = trim((string)($payload['searchTerms'] ?? $filters['searchTerms'] ?? ''));
            $categoryKey = strtolower(trim((string)($filters['categorykey'] ?? '')));
            $subcategoryKey = strtolower(trim((string)($filters['subcategorykey'] ?? '')));

            $page = max(1, (int)($payload['page'] ?? 1));
            $limit = max(1, min(200, (int)($payload['limit'] ?? 9)));
            $offset = ($page - 1) * $limit;

            $sort = strtolower(trim((string)($payload['sort'] ?? '')));

            $priceRange = isset($filters['price_range']) && is_array($filters['price_range']) ? $filters['price_range'] : [];
            $minPrice = isset($priceRange['min']) && $priceRange['min'] !== '' ? (float)$priceRange['min'] : null;
            $maxPrice = isset($priceRange['max']) && $priceRange['max'] !== '' ? (float)$priceRange['max'] : null;

            $priceExpr = "COALESCE(NULLIF(P.final_price, 0), NULLIF(P.sale_price, 0), NULLIF(P.product_price, 0), NULLIF(P.mrp, 0), 0)";

            $builder = $this->productModel->db->table('products AS P');
            $builder->select("
                P.id,
                P.product_name,
                P.slug,
                P.short_description,
                {$priceExpr} AS min_price,
                (SELECT PI.image
                 FROM product_image AS PI
                 WHERE PI.product_id = CAST(P.id AS UNSIGNED)
                   AND PI.status <> 'DELETED'
                 ORDER BY PI.display_order ASC, PI.id ASC
                 LIMIT 1) AS primary_image,
                C.id AS category_id,
                C.category AS category_name,
                C.categorykey AS category_slug,
                SC.id AS subcategory_id,
                SC.category AS subcategory_name,
                SC.categorykey AS subcategory_slug,
                SC.home_static_image AS subcategory_banner_image
            ");

            $builder->join('product_category AS SC', 'SC.id = P.category_id AND SC.status <> \'DELETED\'', 'left');
            $builder->join(
                'product_category AS C',
                'C.id = (CASE WHEN SC.parent_id IS NULL OR SC.parent_id = 0 THEN SC.id ELSE SC.parent_id END) AND C.status <> \'DELETED\'',
                'left'
            );
            $builder->where('P.status <>', 'DELETED');

            if ($subcategoryKey !== '') {
                $escapedSub = $this->productModel->db->escape($subcategoryKey);
                $normalizedSub = rtrim($subcategoryKey, 's');
                $escapedSubLike = $this->productModel->db->escapeLikeString($normalizedSub) . '%';
                $builder->where(
                    "(LOWER(COALESCE(SC.categorykey, '')) = {$escapedSub}
                      OR REPLACE(LOWER(COALESCE(SC.category, '')), ' ', '-') = {$escapedSub}
                      OR EXISTS (
                          SELECT 1
                          FROM product_category CH
                          WHERE CH.parent_id = SC.id
                            AND CH.status <> 'DELETED'
                            AND LOWER(COALESCE(CH.categorykey, '')) = {$escapedSub}
                      )
                      OR REPLACE(LOWER(COALESCE(SC.category, '')), ' ', '-') LIKE " . $this->productModel->db->escape($escapedSubLike) . " ESCAPE '!')",
                    null,
                    false
                );
            }

            if ($categoryKey !== '') {
                $escapedCat = $this->productModel->db->escape($categoryKey);
                $normalizedCat = rtrim($categoryKey, 's');
                $escapedCatLike = $this->productModel->db->escapeLikeString($normalizedCat) . '%';
                $builder->where(
                    "(LOWER(COALESCE(C.categorykey, '')) = {$escapedCat}
                      OR REPLACE(LOWER(COALESCE(C.category, '')), ' ', '-') = {$escapedCat}
                      OR REPLACE(LOWER(COALESCE(C.category, '')), ' ', '-') LIKE " . $this->productModel->db->escape($escapedCatLike) . " ESCAPE '!')",
                    null,
                    false
                );
            }

            if ($searchTerms !== '') {
                $builder->groupStart()
                    ->like('P.product_name', $searchTerms)
                    ->orLike('P.slug', $searchTerms)
                    ->orLike('P.short_description', $searchTerms)
                    ->groupEnd();
            }

            if ($minPrice !== null) {
                $builder->where("{$priceExpr} >=", $minPrice, false);
            }
            if ($maxPrice !== null) {
                $builder->where("{$priceExpr} <=", $maxPrice, false);
            }

            if ($sort === 'price_desc') {
                $builder->orderBy("{$priceExpr}", 'DESC', false);
            } elseif ($sort === 'price_asc') {
                $builder->orderBy("{$priceExpr}", 'ASC', false);
            } else {
                $builder->orderBy('P.home_display_order', 'ASC');
                $builder->orderBy('P.id', 'ASC');
            }

            $total = (int)$builder->countAllResults(false);
            $rows = $builder->limit($limit, $offset)->get()->getResultArray();

            $products = array_map(function (array $row): array {
                $productId = (int)($row['id'] ?? 0);
                $resolvedPrimaryImage = $this->resolvePrimaryImageForResponse($productId, $row['primary_image'] ?? null);
                return [
                    'id' => $productId,
                    'product_name' => (string)($row['product_name'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'short_description' => (string)($row['short_description'] ?? ''),
                    'min_price' => (float)($row['min_price'] ?? 0),
                    'primary_image' => $resolvedPrimaryImage,
                    'category' => [
                        'id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
                        'name' => $row['category_name'] ?? null,
                        'slug' => $row['category_slug'] ?? null,
                    ],
                    'subcategory' => [
                        'id' => isset($row['subcategory_id']) ? (int)$row['subcategory_id'] : null,
                        'name' => $row['subcategory_name'] ?? null,
                        'slug' => $row['subcategory_slug'] ?? null,
                        'banner_image' => $row['subcategory_banner_image'] ?? null,
                    ],
                ];
            }, $rows);

            return json_success([
                'products' => $products,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $limit),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Products::filterV2 error: ' . $e->getMessage());
            log_message('error', 'Products::filterV2 trace: ' . $e->getTraceAsString());
            return json_error('Failed to fetch filtered products', 500);
        }
    }

    public function cat()
    {
        try {
            $cat_list = $this->productModel->getProductCategoryList('ACTIVE');
            if (is_array($cat_list)) {
                $cat_list = array_map(function ($category) {
                    return $this->sanitizeCategoryImages($category);
                }, $cat_list);
            }
            return json_success($cat_list);
        } catch (\Exception $e) {
            log_message('error', 'Category list error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return json_error('Error loading categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Top-level categories only (parent_id IS NULL).
     * Aligns with API flow: Category → Subcategory → Product.
     */
    public function catTopLevel()
    {
        try {
            $cat_list = $this->productModel->getProductCategoryListTopLevel('ACTIVE');
            return json_success($cat_list);
        } catch (\Exception $e) {
            log_message('error', 'Category top-level list error: ' . $e->getMessage());
            return json_error('Error loading categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Subcategories for a given category. Requires category_id.
     */
    public function subcat($categoryId)
    {
        try {
            $categoryId = (int)$categoryId;
            if ($categoryId <= 0) {
                return json_error('Valid category_id is required', 400);
            }
            $subcat_list = $this->productModel->getProductSubcategoryList($categoryId, 'ACTIVE');
            if (is_array($subcat_list)) {
                $subcat_list = array_map(function ($category) {
                    return $this->sanitizeCategoryImages($category);
                }, $subcat_list);
            }
            return json_success($subcat_list);
        } catch (\Exception $e) {
            log_message('error', 'Subcategory list error: ' . $e->getMessage());
            return json_error('Error loading subcategories: ' . $e->getMessage(), 500);
        }
    }

    public function brand()
    {
        try {
            $brand_list = $this->productModel->getProductBrandList('ACTIVE');
            return json_success($brand_list);
        } catch (\Exception $e) {
            log_message('error', 'Brand list error: ' . $e->getMessage());
            return json_error('Error loading brands: ' . $e->getMessage(), 500);
        }
    }

    public function unit()
    {
        try {
            $unit_list = $this->productModel->getUnitList('ACTIVE');
            return json_success($unit_list);
        } catch (\Exception $e) {
            log_message('error', 'Unit list error: ' . $e->getMessage());
            return json_error('Error loading units: ' . $e->getMessage(), 500);
        }
    }

    public function deletepro()
    {
        // Set Content-Type to JSON early to prevent Debug Toolbar HTML injection
        $this->response->setContentType('application/json');
        
        $pId = $this->request->getUri()->getSegment(3);

        if (empty($pId)) {
            return json_error('Product ID is required', 400);
        }

        try {
            log_message('info', 'Products::deletepro - Starting delete for product_id: ' . $pId);
            
            $pData = ['status' => 'DELETED'];
            $result = $this->productModel->updateProduct($pId, $pData);
            
            // CRITICAL: Check if update actually succeeded
            if ($result === false) {
                // Get database errors for detailed error message
                $db = \Config\Database::connect();
                $dbError = $db->error();
                $errorMessage = 'Failed to delete product in database';
                
                if (!empty($dbError['message'])) {
                    $errorMessage .= ': ' . $dbError['message'];
                    log_message('error', 'Products::deletepro - Database error: ' . $dbError['message']);
                }
                
                log_message('error', 'Products::deletepro - Product delete failed. Product ID: ' . $pId);
                return json_error($errorMessage, 500);
            }
            
            // Verify product was actually updated (query includes DELETED so we can verify soft-delete)
            $db = \Config\Database::connect();
            $verifyRow = $db->table('products')->select('id, status')->where('id', (int) $pId)->get()->getRowArray();
            if (empty($verifyRow)) {
                log_message('error', 'Products::deletepro - Product delete succeeded but product not found in database');
                return json_error('Product delete completed but product not found. It may have been already deleted.', 404);
            }
            if (($verifyRow['status'] ?? '') !== 'DELETED') {
                log_message('error', 'Products::deletepro - Product delete succeeded but status is not DELETED. Current status: ' . ($verifyRow['status'] ?? 'unknown'));
                return json_error('Product delete completed but status was not updated correctly.', 500);
            }
            
            EasyEcomSyncService::fire(fn ($s) => $s->updateProduct((int) $pId));

            log_message('info', 'Products::deletepro - Product successfully deleted with ID: ' . $pId . ', verified in database');
            return json_success(null, 'Product has been deleted successfully');
        } catch (\Exception $e) {
            log_message('error', 'Products::deletepro - Exception: ' . $e->getMessage());
            log_message('error', 'Products::deletepro - Stack trace: ' . $e->getTraceAsString());
            return json_error('Error deleting product: ' . $e->getMessage(), 500);
        }
    }

    public function updatestock()
    {
        $pId = $this->request->getUri()->getSegment(3);

        if (empty($pId)) {
            return json_error('Product ID is required', 400);
        }

        // Get JSON input first (for API requests)
        $json = $this->request->getJSON(true);
        
        // Use JSON data if available, otherwise fall back to POST
        $stock = $json['stock'] ?? $this->request->getPost('stock');

        if ($stock === null || $stock === '') {
            return json_error('Stock quantity is required', 400);
        }

        // Validate and prepare stock data using centralized stock helper
        $stock_validation = validate_stock_quantity($stock, 0);
        if (!$stock_validation['valid']) {
            return json_error($stock_validation['message'], 400);
        }
        
        $pData = prepare_stock_data($stock_validation['value']);
        $result = $this->productModel->updateProduct($pId, $pData);
        
        if ($result == true) {
            // EasyEcom inventory sync temporarily disabled — product sync only
            return json_success(null, 'Product stock has been updated successfully');
        } else {
            return json_error('Failed to update product stock', 500);
        }
    }

    public function count()
    {
        try {
            $stats = $this->productModel->getProductCounts();
            return json_success($stats);
        } catch (\Exception $e) {
            log_message('error', 'Product count error: ' . $e->getMessage());
            return json_error('Error getting product counts: ' . $e->getMessage(), 500);
        }
    }

    public function statistics()
    {
        try {
            $stats = $this->productModel->getProductCounts();
            return json_success($stats);
        } catch (\Exception $e) {
            log_message('error', 'Product statistics error: ' . $e->getMessage());
            return json_error('Error getting product statistics: ' . $e->getMessage(), 500);
        }
    }

    public function prices()
    {
        $pId = $this->request->getUri()->getSegment(3);
        
        if (empty($pId)) {
            return json_error('Product ID is required', 400);
        }

        // Get product details (price is now stored in products table)
        $product_details = $this->productModel->getProductDetails($pId);
        
        if (!$product_details) {
            return json_error('Product not found', 404);
        }
        
        // Return price data in the format expected by frontend
        // Return as array with single price entry (for backward compatibility)
        $product_prices = [[
            'id' => $pId, // Use product ID as price ID
            'product_id' => $pId,
            'qty' => 1, // Default quantity (no longer using pricing tiers)
            'unit_id' => $product_details['unit_id'] ?? null,
            'unit_name' => $product_details['unit_name'] ?? '',
            'mrp' => floatval($product_details['mrp'] ?? ($product_details['product_price'] ?? 0)),
            'discount_inr' => floatval($product_details['discount'] ?? 0),
            'discount_percent' => floatval($product_details['discount_off_inpercent'] ?? 0),
            'sale_price' => floatval($product_details['sale_price'] ?? 0),
            'final_price' => floatval($product_details['final_price'] ?? 0),
            'status' => $product_details['status'] ?? 'ACTIVE',
            'created_on' => $product_details['created_on'] ?? '',
            'created_id' => $product_details['created_id'] ?? ''
        ]];
        
        return json_success($product_prices);
    }

    // Bulk Operations
    public function bulk_delete()
    {
        try {
            $json = $this->request->getJSON(true);
            $product_ids = $json['product_ids'] ?? $this->request->getPost('product_ids');
            
            if (empty($product_ids) || !is_array($product_ids)) {
                return json_error('Product IDs array is required', 400);
            }
            
            $result = $this->productModel->bulkDeleteProducts($product_ids);
            
            return json_success($result, 'Bulk delete completed');
        } catch (\Exception $e) {
            log_message('error', 'Bulk delete error: ' . $e->getMessage());
            return json_error('Error performing bulk delete: ' . $e->getMessage(), 500);
        }
    }

    public function bulk_status()
    {
        try {
            $json = $this->request->getJSON(true);
            $product_ids = $json['product_ids'] ?? $this->request->getPost('product_ids');
            $status = $json['status'] ?? $this->request->getPost('status');
            
            if (empty($product_ids) || !is_array($product_ids)) {
                return json_error('Product IDs array is required', 400);
            }
            
            if (empty($status)) {
                return json_error('Status is required', 400);
            }
            
            $result = $this->productModel->bulkUpdateProductStatus($product_ids, $status);
            
            return json_success($result, 'Bulk status update completed');
        } catch (\Exception $e) {
            log_message('error', 'Bulk status error: ' . $e->getMessage());
            return json_error('Error performing bulk status update: ' . $e->getMessage(), 500);
        }
    }

    public function bulk_restore()
    {
        try {
            $json = $this->request->getJSON(true);
            $product_ids = $json['product_ids'] ?? $this->request->getPost('product_ids');
            
            if (empty($product_ids) || !is_array($product_ids)) {
                return json_error('Product IDs array is required', 400);
            }
            
            $result = $this->productModel->bulkRestoreProducts($product_ids);
            
            return json_success($result, 'Bulk restore completed');
        } catch (\Exception $e) {
            log_message('error', 'Bulk restore error: ' . $e->getMessage());
            return json_error('Error performing bulk restore: ' . $e->getMessage(), 500);
        }
    }

    public function bulk_update()
    {
        try {
            $json = $this->request->getJSON(true);
            $product_ids = $json['product_ids'] ?? $this->request->getPost('product_ids');
            // Dashboard sends "data"; support both "data" and "update_data" for API consistency
            $update_data = $json['update_data'] ?? $json['data'] ?? $this->request->getPost('update_data') ?? $this->request->getPost('data');
            
            if (empty($product_ids) || !is_array($product_ids)) {
                return json_error('Product IDs array is required', 400);
            }
            
            if (empty($update_data) || !is_array($update_data)) {
                return json_error('Update data is required', 400);
            }
            
            $result = $this->productModel->bulkUpdateProducts($product_ids, $update_data);
            
            return json_success($result, 'Bulk update completed');
        } catch (\Exception $e) {
            log_message('error', 'Bulk update error: ' . $e->getMessage());
            return json_error('Error performing bulk update: ' . $e->getMessage(), 500);
        }
    }

    // Analytics
    public function analytics()
    {
        try {
            $period = $this->request->getGet('period') ?: '30d';
            $type = $this->request->getGet('type') ?: 'sales';

            $analytics = [];

            switch ($type) {
                case 'sales':
                    $analytics = $this->getSalesAnalytics($period);
                    break;
                case 'stock':
                    $analytics = $this->getStockAnalytics();
                    break;
                case 'products':
                    $analytics = $this->getProductAnalytics($period);
                    break;
                default:
                    return json_error('Invalid analytics type. Must be: sales, stock, or products', 400);
            }

            return json_success($analytics, 'Analytics retrieved successfully');
        } catch (\Exception $e) {
            log_message('error', 'Product analytics error: ' . $e->getMessage());
            return json_error('Failed to retrieve analytics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get sales analytics
     */
    private function getSalesAnalytics($period)
    {
        // Calculate date range based on period
        $dateRanges = [
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '1y' => 365
        ];
        $days = $dateRanges[$period] ?? 30;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // Note: This is a placeholder. Actual implementation would require order/sales data
        // For now, return product-based analytics
        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => date('Y-m-d'),
            'total_products' => $this->productModel->getProductCounts()['total_products'],
            'active_products' => $this->productModel->getProductCounts()['active_products'],
            'note' => 'Full sales analytics requires order/sales data integration'
        ];
    }

    /**
     * Get stock analytics
     */
    private function getStockAnalytics()
    {
        $stats = $this->productModel->getProductCounts();
        
        $db = \Config\Database::connect();
        
        // Get low stock products (stock < 10)
        $builder = $db->table('products');
        $builder->where('stock_quantity <', 10);
        $builder->where('stock_quantity >', 0);
        $builder->where('status <>', 'DELETED');
        $lowStockCount = $builder->countAllResults(false);

        // Get total stock value (sum of all stock quantities)
        $builder = $db->table('products');
        $builder->selectSum('stock_quantity', 'total');
        $builder->where('status <>', 'DELETED');
        $result = $builder->get()->getRowArray();
        $totalStock = (int)($result['total'] ?? 0);

        // Get average stock per product
        $avgStock = $stats['total_products'] > 0 
            ? round($totalStock / $stats['total_products'], 2) 
            : 0;

        return [
            'in_stock' => $stats['products_in_stock'],
            'out_of_stock' => $stats['products_out_of_stock'],
            'low_stock' => $lowStockCount,
            'total_quantity' => $totalStock,
            'average_stock' => $avgStock,
            'by_category' => $stats['by_category'],
            'by_brand' => $stats['by_brand']
        ];
    }

    /**
     * Get product analytics
     */
    private function getProductAnalytics($period)
    {
        $dateRanges = [
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '1y' => 365
        ];
        $days = $dateRanges[$period] ?? 30;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $db = \Config\Database::connect();

        // Get products created in period
        $builder = $db->table('products');
        $builder->where('created_at >=', $startDate);
        $builder->where('status <>', 'DELETED');
        $newProducts = $builder->countAllResults(false);

        // Get products updated in period
        $builder = $db->table('products');
        $builder->where('updated_at >=', $startDate);
        $builder->where('status <>', 'DELETED');
        $updatedProducts = $builder->countAllResults(false);

        // Get top categories by product count
        $builder = $db->table('product_category PC');
        $builder->select('PC.category as category_name, COUNT(P.id) as product_count');
        $builder->join('products P', 'P.category_id = PC.id AND P.status <> \'DELETED\'', 'left');
        $builder->where('PC.status <>', 'DELETED');
        $builder->groupBy('PC.id, PC.category');
        $builder->orderBy('product_count', 'DESC');
        $builder->limit(10);
        $topCategories = $builder->get()->getResultArray();

        // Get top brands by product count
        $builder = $db->table('product_brand PB');
        $builder->select('PB.brand as brand_name, COUNT(P.id) as product_count');
        $builder->join('products P', 'P.brand_id = PB.id AND P.status <> \'DELETED\'', 'left');
        $builder->where('PB.status <>', 'DELETED');
        $builder->groupBy('PB.id, PB.brand');
        $builder->orderBy('product_count', 'DESC');
        $builder->limit(10);
        $topBrands = $builder->get()->getResultArray();

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => date('Y-m-d'),
            'new_products' => $newProducts,
            'updated_products' => $updatedProducts,
            'top_categories' => $topCategories,
            'top_brands' => $topBrands,
            'overall_stats' => $this->productModel->getProductCounts()
        ];
    }

    // Category Management
    public function catadd()
    {
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $category = $this->request->getPost('category');
            $parent_id = $this->request->getPost('parent_id');

            if (empty($category)) {
                return json_error('Category name is required', 400);
            }

            // Validate parent_id if provided
            if (!empty($parent_id)) {
                $parent_id = (int)$parent_id;
                if ($parent_id > 0) {
                    // Check if parent category exists and is active
                    $parent_category = $this->productModel->getproductCategoryDetails($parent_id);
                    if (empty($parent_category) || count($parent_category) == 0) {
                        return json_error('Parent category not found', 400);
                    }
                    $parent_data = $parent_category[0];
                    if ($parent_data['status'] == 'DELETED') {
                        return json_error('Cannot create subcategory under a deleted parent category', 400);
                    }
                } else {
                    $parent_id = null;
                }
            } else {
                $parent_id = null;
            }

            $catlower = strtolower($category);
            $categorykey = str_replace(' ', '-', $catlower);
            $valids_category = $this->productModel->productCategoryExists($category);
            
            if (isset($valids_category) && count($valids_category) > 0) {
                return json_error('Category already exists', 400);
            }

            // Require banner image for category/subcategory creation
            $bannerFile = $this->request->getFile('banner');
            if (!$bannerFile || !$bannerFile->isValid() || $bannerFile->hasMoved()) {
                return json_error('Banner image is required for categories and subcategories', 400);
            }
            $bannerUploadPath = FCPATH . 'assets/category_images/';
            if (!is_dir($bannerUploadPath)) {
                mkdir($bannerUploadPath, 0755, true);
            }
            $bannerName = $bannerFile->getRandomName();
            if (!$bannerFile->move($bannerUploadPath, $bannerName)) {
                return json_error('Failed to upload banner image', 500);
            }
            $home_static_image = base_url('assets/category_images/' . $bannerName);

            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $created_id = $checkuservars['userid'] ?? null;
            $created_on = date('Y-m-d H:i:s');
            $status = 'ACTIVE';
            $logo = '';
            $logoStatus = 'DELETED';
            $bannerStatus = 'ACTIVE';

            // Handle logo file upload (optional)
            $file = $this->request->getFile('logo');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/category_logos/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $logo = $newName;
                    $logoStatus = 'ACTIVE';
                }
            }

            $categoryArr = [
                'category' => $category,
                'categorykey' => $categorykey,
                'logo' => $logo,
                'logo_status' => $logoStatus,
                'home_static_image' => $home_static_image,
                'banner_status' => $bannerStatus,
                'created_id' => $created_id,
                'created_on' => $created_on,
                'status' => $status
            ];

            // Add parent_id if provided
            if ($parent_id !== null) {
                $categoryArr['parent_id'] = $parent_id;
            }

            log_message('info', 'Products::catadd - Attempting to insert category: ' . $category);
            
            $result = $this->productModel->insertProductCategory($categoryArr);
            
            // CRITICAL: Check if insert actually succeeded
            if ($result === false || $result <= 0) {
                // Get database errors for detailed error message
                $db = \Config\Database::connect();
                $dbError = $db->error();
                $errorMessage = 'Failed to save category to database';
                
                if (!empty($dbError['message'])) {
                    $errorMessage .= ': ' . $dbError['message'];
                    log_message('error', 'Products::catadd - Database error: ' . $dbError['message']);
                }
                
                log_message('error', 'Products::catadd - Category insert failed. Category data: ' . json_encode($categoryArr));
                return json_error($errorMessage, 500);
            }
            
            // CRITICAL: Verify category was actually saved to database
            $verifyCategory = $this->productModel->getproductCategoryDetails($result);
            if (empty($verifyCategory)) {
                log_message('error', 'Products::catadd - Category insert returned ID ' . $result . ' but category not found in database');
                return json_error('Category was not saved to database. Please try again.', 500);
            }
            
            log_message('info', 'Products::catadd - Category successfully created with ID: ' . $result . ', verified in database');
            return json_success(['id' => $result], 'Category has been added successfully');
        } else {
            // Handle GET (return reset/default form data for frontend)
            $reset = [
                'name' => '',
                'slug' => '',
                'status' => 'ACTIVE',
                'image' => '',
                'bannerImage' => '',
                'parent_id' => null,
            ];
            return json_success(['reset' => $reset], 'Add category form defaults');
        }
    }

    public function catedit()
    {
        $catId = $this->request->getUri()->getSegment(3);

        if (empty($catId)) {
            return json_error('Category ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $category = $this->request->getPost('category');
            $status = $this->request->getPost('status') ?: 'INACTIVE';
            $parent_id = $this->request->getPost('parent_id');
            $logo_status = $this->request->getPost('logo_status');
            $banner_status = $this->request->getPost('banner_status');

            if (empty($category)) {
                return json_error('Category name is required', 400);
            }

            // Get current category details
            $current_category = $this->productModel->getproductCategoryDetails($catId);
            if (empty($current_category) || count($current_category) == 0) {
                return json_error('Category not found', 404);
            }

            // Validate parent_id if provided
            if (isset($parent_id) && $parent_id !== '' && $parent_id !== null) {
                $parent_id = (int)$parent_id;
                if ($parent_id > 0) {
                    // Prevent circular reference: category cannot be its own parent
                    if ($parent_id == $catId) {
                        return json_error('Category cannot be its own parent', 400);
                    }

                    // Check if parent category exists and is active
                    $parent_category = $this->productModel->getproductCategoryDetails($parent_id);
                    if (empty($parent_category) || count($parent_category) == 0) {
                        return json_error('Parent category not found', 400);
                    }
                    $parent_data = $parent_category[0];
                    if ($parent_data['status'] == 'DELETED') {
                        return json_error('Cannot set parent to a deleted category', 400);
                    }

                    // Prevent circular reference: check if the new parent is a descendant of this category
                    $isDescendant = $this->productModel->isCategoryDescendant($parent_id, $catId);
                    if ($isDescendant) {
                        return json_error('Cannot set parent: would create circular reference', 400);
                    }
                } else {
                    $parent_id = null;
                }
            } else {
                // If parent_id is not provided, keep the existing parent_id
                $parent_id = $current_category[0]['parent_id'] ?? null;
            }

            $valids_cat = $this->productModel->productCategory_edit($catId, $category);
            
            if (isset($valids_cat) && count($valids_cat) > 0) {
                return json_error('Category already exists', 400);
            }

            $catlower = strtolower($category);
            $categorykey = str_replace(' ', '-', $catlower);

            $categoryArr = [
                'category' => $category,
                'categorykey' => $categorykey,
                'status' => $status,
                'parent_id' => $parent_id
            ];

            if (!empty($logo_status)) {
                $normalizedLogoStatus = strtoupper(trim((string)$logo_status));
                if (!in_array($normalizedLogoStatus, ['ACTIVE', 'DELETED'], true)) {
                    return json_error('Invalid logo_status. Allowed values: ACTIVE, DELETED', 400);
                }
                $categoryArr['logo_status'] = $normalizedLogoStatus;
            }

            if (!empty($banner_status)) {
                $normalizedBannerStatus = strtoupper(trim((string)$banner_status));
                if (!in_array($normalizedBannerStatus, ['ACTIVE', 'DELETED'], true)) {
                    return json_error('Invalid banner_status. Allowed values: ACTIVE, DELETED', 400);
                }
                $categoryArr['banner_status'] = $normalizedBannerStatus;
            }

            // Handle logo file upload if provided
            $file = $this->request->getFile('logo');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/category_logos/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $categoryArr['logo'] = $newName;
                    $categoryArr['logo_status'] = 'ACTIVE';
                }
            }

            // Handle banner image upload if provided (stored in home_static_image)
            $bannerFile = $this->request->getFile('banner');
            if ($bannerFile && $bannerFile->isValid() && !$bannerFile->hasMoved()) {
                $bannerUploadPath = FCPATH . 'assets/category_images/';
                if (!is_dir($bannerUploadPath)) {
                    mkdir($bannerUploadPath, 0755, true);
                }
                $bannerName = $bannerFile->getRandomName();
                if ($bannerFile->move($bannerUploadPath, $bannerName)) {
                    $categoryArr['home_static_image'] = base_url('assets/category_images/' . $bannerName);
                    $categoryArr['banner_status'] = 'ACTIVE';
                }
            }

            log_message('info', 'Products::catedit - Attempting to update category ID: ' . $catId);
            
            $result = $this->productModel->updateProductCategory($catId, $categoryArr);
            
            // CRITICAL: Check if update actually succeeded
            if ($result === false) {
                // Get database errors for detailed error message
                $db = \Config\Database::connect();
                $dbError = $db->error();
                $errorMessage = 'Failed to update category in database';
                
                if (!empty($dbError['message'])) {
                    $errorMessage .= ': ' . $dbError['message'];
                    log_message('error', 'Products::catedit - Database error: ' . $dbError['message']);
                }
                
                log_message('error', 'Products::catedit - Category update failed. Category ID: ' . $catId . ', Update data: ' . json_encode($categoryArr));
                return json_error($errorMessage, 500);
            }

            // If we soft-deleted the category, getproductCategoryDetails won't return it (excludes DELETED) - that's expected
            if (!empty($categoryArr['status']) && $categoryArr['status'] === 'DELETED') {
                log_message('info', 'Products::catedit - Category ID: ' . $catId . ' marked as deleted');
                return json_success(null, 'Category has been updated successfully');
            }
            
            // Verify category was actually updated (check if it still exists and has correct data)
            $verifyCategory = $this->productModel->getproductCategoryDetails($catId);
            if (empty($verifyCategory)) {
                log_message('error', 'Products::catedit - Category update succeeded but category not found in database (may have been deleted)');
                return json_error('Category update completed but category not found. It may have been deleted.', 404);
            }
            
            log_message('info', 'Products::catedit - Category successfully updated with ID: ' . $catId . ', verified in database');
            return json_success(null, 'Category has been updated successfully');
        } else {
            // Handle GET (retrieve)
            $cat_details = $this->productModel->getproductCategoryDetails($catId);
            
            if ($cat_details) {
                $cat_details = array_map(function ($category) {
                    return $this->sanitizeCategoryImages($category);
                }, $cat_details);
                return json_success($cat_details);
            } else {
                return json_error('Category not found', 404);
            }
        }
    }

    // Brand Management
    public function brandadd()
    {
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $brand = $this->request->getPost('brand');

            if (empty($brand)) {
                return json_error('Brand name is required', 400);
            }

            $brandlower = strtolower($brand);
            $brandkey = str_replace(' ', '-', $brandlower);
            $valids_brand = $this->productModel->productBrandExists($brand);
            
            if (isset($valids_brand) && count($valids_brand) > 0) {
                return json_error('Brand already exists', 400);
            }

            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $created_id = $checkuservars['userid'] ?? null;
            $created_on = date('Y-m-d H:i:s');
            $status = 'ACTIVE';
            $logo = '';

            // Handle file upload
            $file = $this->request->getFile('logo');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/brand_logos/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $logo = $newName;
                }
            }

            $brandArr = [
                'brand' => $brand,
                'brandkey' => $brandkey,
                'logo' => $logo,
                'created_id' => $created_id,
                'created_on' => $created_on,
                'status' => $status
            ];

            $result = $this->productModel->insertProductBrand($brandArr);
            
            if ($result > 0) {
                return json_success(['id' => $result], 'Brand has been added successfully');
            } else {
                return json_error('Failed to add brand', 500);
            }
        } else {
            // Handle GET
            return json_success(null, 'Add brand endpoint. Send POST with brand name and optional logo file');
        }
    }

    public function brandedit()
    {
        $brandId = $this->request->getUri()->getSegment(3);

        if (empty($brandId)) {
            return json_error('Brand ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $brand = $this->request->getPost('brand');
            $status = $this->request->getPost('status') ?: 'INACTIVE';
            $home_display_status = $this->request->getPost('home_display_status') ?: 'NO';
            $sequences = $this->request->getPost('sequences') ?: '0';

            if (empty($brand)) {
                return json_error('Brand name is required', 400);
            }

            $valids_brand = $this->productModel->productBrand_edit($brandId, $brand);
            
            if (isset($valids_brand) && count($valids_brand) > 0) {
                return json_error('Brand already exists', 400);
            }

            $brandlower = strtolower($brand);
            $brandkey = str_replace(' ', '-', $brandlower);

            $brandArr = [
                'brand' => $brand,
                'brandkey' => $brandkey,
                'status' => $status,
                'home_display_status' => $home_display_status,
                'sequences' => $sequences,
            ];

            // Handle file upload if provided
            $file = $this->request->getFile('logo');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/brand_logos/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $brandArr['logo'] = $newName;
                }
            }

            $result = $this->productModel->updateProductBrand($brandId, $brandArr);
            
            if ($result == true) {
                return json_success(null, 'Brand has been updated successfully');
            } else {
                return json_error('Failed to update brand', 500);
            }
        } else {
            // Handle GET (retrieve)
            $brand_details = $this->productModel->getproductBrandDetails($brandId);
            
            if ($brand_details) {
                return json_success($brand_details);
            } else {
                return json_error('Brand not found', 404);
            }
        }
    }

    // Deprecated Price Methods
    public function priceadd()
    {
        // DEPRECATED: Price is now stored directly in products table
        return json_error('This endpoint is deprecated. Price is now stored in the product itself. Use the product edit endpoint to update price.', 410);
    }

    public function priceedit()
    {
        // DEPRECATED: Price is now stored directly in products table
        return json_error('This endpoint is deprecated. Price is now stored in the product itself. Use the product edit endpoint to update price.', 410);
    }

    public function priceedit_old()
    {
        // DEPRECATED: Price is now stored directly in products table
        return json_error('This endpoint is deprecated. Price is now stored in the product itself. Use the product edit endpoint to update price.', 410);
    }

    public function deleteprice()
    {
        // DEPRECATED: Price is now stored directly in products table
        return json_error('This endpoint is deprecated. Price is now stored in the product itself. Use the product edit endpoint to update price.', 410);
    }

    // Product Variations
    public function variations()
    {
        $productId = $this->request->getUri()->getSegment(3);

        if (empty($productId)) {
            return json_error('Product ID is required', 400);
        }

        // Sanitize product ID
        $productId = (int)$productId;
        if ($productId <= 0) {
            return json_error('Invalid Product ID', 400);
        }

        // Verify product exists
        if (!$this->productModel->productExists($productId)) {
            return json_error('Product not found', 404);
        }

        $variations = $this->productModel->getProductVariations($productId);
        
        return json_success($variations);
    }

    public function variationadd()
    {
        $productId = $this->request->getUri()->getSegment(3);

        if (empty($productId)) {
            return json_error('Product ID is required', 400);
        }

        // Sanitize product ID
        $productId = (int)$productId;
        if ($productId <= 0) {
            return json_error('Invalid Product ID', 400);
        }

        // Verify product exists
        if (!$this->productModel->productExists($productId)) {
            return json_error('Product not found', 404);
        }

        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            // Get JSON input first (for API requests)
            $json = $this->request->getJSON(true);
            
            // Use JSON data if available, otherwise fall back to POST
            $sku = $json['sku'] ?? $this->request->getPost('sku');
            $variation_name = $json['variation_name'] ?? $this->request->getPost('variation_name');
            $price = floatval($json['price'] ?? $this->request->getPost('price') ?? 0);
            $final_price = floatval($json['final_price'] ?? $this->request->getPost('final_price') ?? 0);
            $discount_percent = floatval($json['discount_percent'] ?? $this->request->getPost('discount_percent') ?? 0);
            $discount_value = floatval($json['discount_value'] ?? $this->request->getPost('discount_value') ?? 0);
            $stock_quantity = (int)($json['stock_quantity'] ?? $this->request->getPost('stock_quantity') ?? 0);
            $status = $json['status'] ?? $this->request->getPost('status') ?? 'ACTIVE';
            // API-aligned: color, size (per product_variations schema)
            $color_name = $json['color_name'] ?? $this->request->getPost('color_name');
            $color_code = $json['color_code'] ?? $this->request->getPost('color_code');
            $size = $json['size'] ?? $this->request->getPost('size');

            if (empty($sku) || empty($variation_name)) {
                return json_error('SKU and Variation Name are required', 400);
            }

            // Validate SKU uniqueness
            $existingSku = $this->productModel->variationSkuExists($sku);
            if (!empty($existingSku)) {
                return json_error('SKU already exists', 400);
            }

            // Ensure price has 2 decimal places
            $price = round($price, 2);
            $final_price = round($final_price, 2);
            $discount_percent = round($discount_percent, 2);
            $discount_value = round($discount_value, 2);

            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $created_id = $checkuservars['userid'] ?? null;

            $variationArr = [
                'product_id' => $productId,
                'sku' => $sku,
                'variation_name' => $variation_name,
                'price' => $price,
                'final_price' => $final_price,
                'discount_percent' => $discount_percent,
                'discount_value' => $discount_value,
                'stock_quantity' => $stock_quantity,
                'status' => $status,
                'is_deleted' => 0
            ];
            if ($color_name !== null && $color_name !== '') {
                $variationArr['color_name'] = $color_name;
            }
            if ($color_code !== null && $color_code !== '') {
                $variationArr['color_code'] = $color_code;
            }
            if ($size !== null && $size !== '') {
                $variationArr['size'] = $size;
            }

            $result = $this->productModel->insertProductVariation($variationArr);
            
            if ($result > 0) {
                EasyEcomSyncService::fire(fn ($s) => $s->syncVariation((int) $result, ['product_id' => (int) $productId, 'is_new' => true]));
                return json_success(['id' => $result], 'Variation has been added successfully');
            } else {
                return json_error('Failed to add variation', 500);
            }
        } else {
            return json_error('POST method required', 405);
        }
    }

    public function variationedit()
    {
        $variationId = $this->request->getUri()->getSegment(3);

        if (empty($variationId)) {
            return json_error('Variation ID is required', 400);
        }

        // Sanitize variation ID
        $variationId = (int)$variationId;
        if ($variationId <= 0) {
            return json_error('Invalid Variation ID', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            // Get JSON input first (for API requests)
            $json = $this->request->getJSON(true);
            
            // Use JSON data if available, otherwise fall back to POST
            $sku = $json['sku'] ?? $this->request->getPost('sku');
            $variation_name = $json['variation_name'] ?? $this->request->getPost('variation_name');
            $price = isset($json['price']) ? floatval($json['price']) : ($this->request->getPost('price') !== null ? floatval($this->request->getPost('price')) : null);
            $final_price = isset($json['final_price']) ? floatval($json['final_price']) : ($this->request->getPost('final_price') !== null ? floatval($this->request->getPost('final_price')) : null);
            $discount_percent = isset($json['discount_percent']) ? floatval($json['discount_percent']) : ($this->request->getPost('discount_percent') !== null ? floatval($this->request->getPost('discount_percent')) : null);
            $discount_value = isset($json['discount_value']) ? floatval($json['discount_value']) : ($this->request->getPost('discount_value') !== null ? floatval($this->request->getPost('discount_value')) : null);
            $stock_quantity = isset($json['stock_quantity']) ? (int)$json['stock_quantity'] : ($this->request->getPost('stock_quantity') !== null ? (int)$this->request->getPost('stock_quantity') : null);
            $status = $json['status'] ?? $this->request->getPost('status');
            $color_name = $json['color_name'] ?? $this->request->getPost('color_name');
            $color_code = $json['color_code'] ?? $this->request->getPost('color_code');
            $size = $json['size'] ?? $this->request->getPost('size');

            // Get current variation details
            $current_variation = $this->productModel->getProductVariationDetails($variationId);
            if (!$current_variation) {
                return json_error('Variation not found', 404);
            }

            // Build update array (only include fields that are provided)
            $variationArr = [];

            if ($sku !== null && $sku !== '') {
                // Validate SKU uniqueness (excluding current variation)
                $existingSku = $this->productModel->variationSkuExists($sku, $variationId);
                if (!empty($existingSku)) {
                    return json_error('SKU already exists', 400);
                }
                $variationArr['sku'] = $sku;
            }

            if ($variation_name !== null && $variation_name !== '') {
                $variationArr['variation_name'] = $variation_name;
            }

            if ($price !== null) {
                $variationArr['price'] = round($price, 2);
            }
            if ($final_price !== null) {
                $variationArr['final_price'] = round($final_price, 2);
            }

            if ($discount_percent !== null) {
                $variationArr['discount_percent'] = round($discount_percent, 2);
            }

            if ($discount_value !== null) {
                $variationArr['discount_value'] = round($discount_value, 2);
            }

            if ($stock_quantity !== null) {
                $variationArr['stock_quantity'] = $stock_quantity;
            }

            if ($status !== null && $status !== '') {
                $variationArr['status'] = $status;
            }
            if ($color_name !== null) {
                $variationArr['color_name'] = $color_name === '' ? null : (string)$color_name;
            }
            if ($color_code !== null) {
                $variationArr['color_code'] = $color_code === '' ? null : (string)$color_code;
            }
            if ($size !== null) {
                $variationArr['size'] = $size === '' ? null : (string)$size;
            }

            if (empty($variationArr)) {
                return json_error('No fields to update', 400);
            }

            $result = $this->productModel->updateProductVariation($variationId, $variationArr);
            
            if ($result == true) {
                $productIdForSync = (int) ($current_variation['product_id'] ?? 0);
                if ($productIdForSync > 0) {
                    EasyEcomSyncService::fire(fn ($s) => $s->updateVariation((int) $variationId, ['product_id' => $productIdForSync]));
                }
                return json_success(null, 'Variation has been updated successfully');
            } else {
                return json_error('Failed to update variation', 500);
            }
        } else {
            // Handle GET (retrieve) - API-aligned: include color_name, color_code, size
            $variation_details = $this->productModel->getProductVariationDetails($variationId);
            
            if ($variation_details) {
                return json_success($variation_details);
            } else {
                return json_error('Variation not found', 404);
            }
        }
    }

    public function variationdelete()
    {
        $variationId = $this->request->getUri()->getSegment(3);

        if (empty($variationId)) {
            return json_error('Variation ID is required', 400);
        }

        // Sanitize variation ID
        $variationId = (int)$variationId;
        if ($variationId <= 0) {
            return json_error('Invalid Variation ID', 400);
        }

        $result = $this->productModel->softDeleteProductVariation($variationId);
        
        if ($result == true) {
            return json_success(null, 'Variation has been deleted successfully');
        } else {
            return json_error('Failed to delete variation', 500);
        }
    }

    public function variationstock()
    {
        $variationId = $this->request->getUri()->getSegment(3);

        if (empty($variationId)) {
            return json_error('Variation ID is required', 400);
        }

        // Get JSON input first (for API requests)
        $json = $this->request->getJSON(true);
        
        // Use JSON data if available, otherwise fall back to POST
        $stock = $json['stock_quantity'] ?? $this->request->getPost('stock_quantity');

        if ($stock === null || $stock === '') {
            return json_error('Stock quantity is required', 400);
        }

        // Validate stock quantity
        $stock_validation = validate_stock_quantity($stock, 0);
        if (!$stock_validation['valid']) {
            return json_error($stock_validation['message'], 400);
        }
        
        $variationArr = ['stock_quantity' => $stock_validation['value']];
        $result = $this->productModel->updateProductVariation($variationId, $variationArr);
        
        if ($result == true) {
            EasyEcomSyncService::fire(fn ($s) => $s->syncVariationInventory((int) $variationId, ['quantity' => $stock_validation['value']]));
            return json_success(null, 'Variation stock has been updated successfully');
        } else {
            return json_error('Failed to update variation stock', 500);
        }
    }

    public function variationpricing()
    {
        $variationId = $this->request->getUri()->getSegment(3);

        if (empty($variationId)) {
            return json_error('Variation ID is required', 400);
        }

        // Get JSON input first (for API requests)
        $json = $this->request->getJSON(true);
        
        // Use JSON data if available, otherwise fall back to POST
        $price = isset($json['price']) ? floatval($json['price']) : ($this->request->getPost('price') ? floatval($this->request->getPost('price')) : null);
        $discount_percent = isset($json['discount_percent']) ? floatval($json['discount_percent']) : ($this->request->getPost('discount_percent') ? floatval($this->request->getPost('discount_percent')) : null);
        $discount_value = isset($json['discount_value']) ? floatval($json['discount_value']) : ($this->request->getPost('discount_value') ? floatval($this->request->getPost('discount_value')) : null);

        if ($price === null && $discount_percent === null && $discount_value === null) {
            return json_error('At least one pricing field (price, discount_percent, discount_value) is required', 400);
        }

        $variationArr = [];

        if ($price !== null) {
            $variationArr['price'] = round($price, 2);
        }

        if ($discount_percent !== null) {
            $variationArr['discount_percent'] = round($discount_percent, 2);
        }

        if ($discount_value !== null) {
            $variationArr['discount_value'] = round($discount_value, 2);
        }

        $result = $this->productModel->updateProductVariation($variationId, $variationArr);
        
        if ($result == true) {
            return json_success(null, 'Variation pricing has been updated successfully');
        } else {
            return json_error('Failed to update variation pricing', 500);
        }
    }

    public function add()
    {
        // Set Content-Type to JSON early to prevent Debug Toolbar HTML injection
        $this->response->setContentType('application/json');
        
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            try {
                // CRITICAL: Check Content-Type to determine how to parse request
                $contentType = $this->request->getHeaderLine('Content-Type');
                $hasFiles = !empty($this->request->getFiles());
                $isFormData = strpos($contentType, 'multipart/form-data') !== false || $hasFiles;
                
                log_message('debug', 'Products::add - ContentType=' . $contentType . ' FormData=' . ($isFormData ? 'Y' : 'N'));
                
                // CRITICAL: For FormData, use getPost(). For JSON, use getJSON()
                // Do NOT call getJSON() on FormData requests as it can interfere with parsing
                if ($isFormData) {
                    $postData = $this->request->getPost();
                    $data = $postData;
                    
                    // CRITICAL: Check if getPost() returned empty - might be a parsing issue
                    if (empty($data) || count($data) === 0) {
                        log_message('error', 'Products::add - getPost() returned empty');
                        $rawBody = $this->request->getBody();
                        if (strlen($rawBody) > 0) {
                            return json_error('FormData received but getPost() returned empty.', 400);
                        }
                        return json_error('No data received from FormData. Content-Type: ' . $contentType, 400);
                    }
                } else {
                    $json = $this->request->getJSON(true);
                    $data = $json;
                    
                    if (empty($data)) {
                        return json_error('No data received. Check Content-Type and request body.', 400);
                    }
                }
                
                // Extract fields from data
                // CRITICAL: Handle FormData string values - check for "null", "undefined", empty strings
                $category_id_raw = $data['category_id'] ?? null;
                $category_id = ($category_id_raw === 'null' || $category_id_raw === 'undefined' || $category_id_raw === '' || $category_id_raw === null) ? null : $category_id_raw;
                
                $brand_id_raw = $data['brand_id'] ?? null;
                $brand_id = ($brand_id_raw === 'null' || $brand_id_raw === 'undefined' || $brand_id_raw === '' || $brand_id_raw === null) ? null : $brand_id_raw;
                
                // If brand_id is not provided or is empty, get first active brand or use 0
                if (empty($brand_id) || $brand_id === null) {
                    $brand_list = $this->productModel->getProductBrandList('ACTIVE');
                    if (!empty($brand_list) && isset($brand_list[0]['id'])) {
                        $brand_id = $brand_list[0]['id'];
                    } else {
                        $brand_id = 0; // Fallback to 0 if no brands exist
                    }
                } else {
                    // Convert to integer if it's a valid number
                    $brand_id = is_numeric($brand_id) ? (int)$brand_id : 0;
                }
                
                $product_code_raw = $data['product_code'] ?? null;
                $product_code = ($product_code_raw === 'null' || $product_code_raw === 'undefined') ? null : $product_code_raw;

                // Product code is required, category_id is optional
                if (empty($product_code)) {
                    log_message('error', 'Products::add - Product code is empty or null');
                    return json_error('Product Code is required', 400);
                }
                
                // If category_id is provided, validate it exists and is a leaf category (optional)
                $final_category_id = null;
                if (!empty($category_id) && $category_id !== null) {
                    // Convert to integer if it's a valid number
                    $category_id = is_numeric($category_id) ? (int)$category_id : null;
                    
                    if ($category_id > 0) {
                        $category_check = $this->productModel->getProductCategoryList('ACTIVE');
                        $category_exists = false;
                        foreach ($category_check as $cat) {
                            if ($cat['id'] == $category_id) {
                                $category_exists = true;
                                $final_category_id = (int)$category_id;
                                break;
                            }
                        }
                        if (!$category_exists) {
                            log_message('error', 'Products::add - Category ID ' . $category_id . ' does not exist');
                            return json_error('Selected category does not exist', 400);
                        }
                    }
                }

                // Validate subcategory_id if provided: must be child of category_id
                $subcategory_id_raw = $data['subcategory_id'] ?? null;
                $subcategory_id = ($subcategory_id_raw === 'null' || $subcategory_id_raw === 'undefined' || $subcategory_id_raw === '' || $subcategory_id_raw === null) ? null : (int)$subcategory_id_raw;
                if ($subcategory_id > 0 && $final_category_id > 0) {
                    $subcats = $this->productModel->getProductSubcategoryList($final_category_id, 'ACTIVE');
                    $valid_subcat = false;
                    foreach ($subcats as $sc) {
                        if ((int)$sc['id'] === $subcategory_id) {
                            $valid_subcat = true;
                            break;
                        }
                    }
                    if (!$valid_subcat) {
                        return json_error('Selected subcategory does not belong to the selected category', 400);
                    }
                }

                $valids_pcode = $this->productModel->productCodeExists($product_code);
                if (isset($valids_pcode) && count($valids_pcode) > 0) {
                    return json_error('Product code already exists', 400);
                }

                // Get all product fields from data
                // CRITICAL: Handle FormData string values - check for "null", "undefined", empty strings
                $product_name_raw = $data['product_name'] ?? null;
                $product_name = ($product_name_raw === 'null' || $product_name_raw === 'undefined' || $product_name_raw === '') ? null : $product_name_raw;
                
                // Validate product_name is not empty
                if (empty($product_name)) {
                    log_message('error', 'Products::add - Product name is empty or null');
                    return json_error('Product name is required', 400);
                }

                // ========== SLUG: Generate BEFORE save, check uniqueness, then save ==========
                // Step 1: Generate slug from product_name (or use provided slug)
                $slug = null;
                $provided_slug_raw = $data['slug'] ?? null;
                $has_valid_provided_slug = isset($data['slug']) && $provided_slug_raw !== null && $provided_slug_raw !== '' && $provided_slug_raw !== 'null' && $provided_slug_raw !== 'undefined';
                if ($has_valid_provided_slug) {
                    $slug = strtolower(trim((string)$provided_slug_raw));
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                    $slug = preg_replace('/^-+|-+$/', '', $slug);
                }
                if (empty($slug)) {
                    // Auto-generate from product_name
                    $slug = strtolower(trim($product_name));
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                    $slug = preg_replace('/^-+|-+$/', '', $slug);
                }
                if (empty($slug)) {
                    return json_error('Could not generate a valid slug from product name.', 400);
                }
                // Step 2: Check slug exists in DB - if yes, REJECT (do not save)
                if ($this->productModel->productSlugExists($slug)) {
                    return json_error('A product with this slug already exists. Please use a different product name or slug.', 400);
                }
                // Slug is unique - will be saved with product below
                log_message('info', 'Products::add - Slug generated and validated: ' . $slug . ' from product_name: ' . $product_name);
                
                // Price fields - use mrp as primary, support legacy product_price field
                // CRITICAL: Handle FormData string values - convert properly
                $mrp_raw = $data['mrp'] ?? $data['product_price'] ?? 0;
                $mrp = is_numeric($mrp_raw) ? floatval($mrp_raw) : 0;
                
                $discount_raw = $data['discount'] ?? $data['discount_price'] ?? 0;
                $discount = is_numeric($discount_raw) ? floatval($discount_raw) : 0;
                
                $discount_off_inpercent_raw = $data['discount_off_inpercent'] ?? 0;
                $discount_off_inpercent = is_numeric($discount_off_inpercent_raw) ? floatval($discount_off_inpercent_raw) : 0;
                
                // Unit ID - can be null/0 if not provided, otherwise use provided value
                // CRITICAL: Handle FormData string values
                $unit_id_raw = $data['unit_id'] ?? null;
                if ($unit_id_raw === null || $unit_id_raw === '' || $unit_id_raw === 'undefined' || $unit_id_raw === 'null') {
                    // Try to get first available unit as default
                    $unit_list = $this->productModel->getUnitList('ACTIVE');
                    $unit_id = (!empty($unit_list) && isset($unit_list[0]['id'])) ? (int)$unit_list[0]['id'] : null;
                } else {
                    $unit_id = is_numeric($unit_id_raw) ? (int)$unit_id_raw : 0;
                    // If unit_id is 0 or negative, set to null
                    if ($unit_id <= 0) {
                        $unit_id = null;
                    }
                }
                
                // CRITICAL: Handle FormData string values for optional fields
                $sku_number_raw = $data['sku_number'] ?? null;
                $sku_number = ($sku_number_raw === 'null' || $sku_number_raw === 'undefined' || $sku_number_raw === '') ? null : $sku_number_raw;
                
                $short_description_raw = $data['short_description'] ?? null;
                $short_description = ($short_description_raw === 'null' || $short_description_raw === 'undefined' || $short_description_raw === '') ? null : $short_description_raw;
                
                $product_description_raw = $data['product_description'] ?? null;
                $product_description = ($product_description_raw === 'null' || $product_description_raw === 'undefined' || $product_description_raw === '') ? null : $product_description_raw;
                
                // Stock management - use stock_quantity from request, calculate in_stock using centralized helper
                // CRITICAL: Handle FormData string values
                $stock_quantity_raw = $data['stock_quantity'] ?? $data['stock'] ?? 0;
                if ($stock_quantity_raw === 'null' || $stock_quantity_raw === 'undefined' || $stock_quantity_raw === '') {
                    $stock_quantity_raw = 0;
                }
                $stock_validation = validate_stock_quantity($stock_quantity_raw, 0);
                $stock_quantity = $stock_validation['valid'] ? $stock_validation['value'] : 0;
                $stock_data = prepare_stock_data($stock_quantity);
                $in_stock = $stock_data['in_stock'];
                
                $product_type_raw = $data['product_type'] ?? 'NA';
                $product_type = ($product_type_raw === 'null' || $product_type_raw === 'undefined' || $product_type_raw === '') ? 'NA' : $product_type_raw;
                
                $gst_rate_raw = $data['gst_rate'] ?? 0;
                $gst_rate = is_numeric($gst_rate_raw) ? floatval($gst_rate_raw) : 0;

                $session = \Config\Services::session();
                $checkuservars = $session->get();
                $created_id = $checkuservars['userid'] ?? null;
                $created_on = date('Y-m-d H:i:s');
                $status = 'ACTIVE';

                // Calculate sale_price and final_price using centralized pricing helper
                $pricing = calculate_all_prices($mrp, $discount, $discount_off_inpercent, $gst_rate);
                $sale_price = $pricing['sale_price'];
                $final_price = $pricing['final_price'];
                
                // Get unit name if unit_id is provided
                $unit_name = null;
                if ($unit_id !== null && $unit_id > 0) {
                    $unitDetails = $this->productModel->getUnitDetails('ACTIVE', $unit_id);
                    $unit_name = (!empty($unitDetails) && isset($unitDetails[0]['unit_name']) && $unitDetails[0]['unit_name'] != '') ? $unitDetails[0]['unit_name'] : null;
                }

                // Handle new product fields
                $subcategory_id = null;
                if (isset($data['subcategory_id'])) {
                    $subcategory_id_raw = $data['subcategory_id'];
                    $subcategory_id = ($subcategory_id_raw === 'null' || $subcategory_id_raw === 'undefined' || $subcategory_id_raw === '' || $subcategory_id_raw === null) ? null : (int)$subcategory_id_raw;
                    if ($subcategory_id <= 0) {
                        $subcategory_id = null;
                    }
                }

                $productArr = [
                    'category_id' => $final_category_id, // Can be NULL if not provided
                    'subcategory_id' => $subcategory_id, // Can be NULL if not provided
                    'brand_id' => (int)$brand_id, // Ensure it's an integer
                    'product_code' => $product_code,
                    'product_name' => $product_name,
                    'slug' => $slug, // Auto-generated from product_name if not provided
                    'model' => $data['model'] ?? null,
                    // Price fields (consolidated)
                    'mrp' => $mrp,
                    'discount' => $discount,
                    'discount_off_inpercent' => $discount_off_inpercent,
                    'sale_price' => $sale_price,
                    'final_price' => $final_price,
                    'unit_id' => ($unit_id !== null && $unit_id > 0) ? $unit_id : null,
                    'unit_name' => $unit_name,
                    // Legacy fields for backward compatibility
                    'product_price' => $mrp,
                    'discount_price' => $discount,
                    // Other fields
                    'sku_number' => $sku_number,
                    'stock_quantity' => $stock_quantity, // Primary stock field
                    'in_stock' => $in_stock, // Derived from stock_quantity
                    'gst_rate' => $gst_rate,
                    'weight' => isset($data['weight']) && is_numeric($data['weight']) ? floatval($data['weight']) : null,
                    'dimensions' => $data['dimensions'] ?? null,
                    'visibility' => isset($data['visibility']) && in_array($data['visibility'], ['PUBLIC', 'HIDDEN']) ? $data['visibility'] : 'PUBLIC',
                    'featured' => isset($data['featured']) && in_array($data['featured'], ['YES', 'NO']) ? $data['featured'] : 'NO',
                    'home_display_order' => isset($data['home_display_order']) && is_numeric($data['home_display_order']) ? (int)$data['home_display_order'] : 0,
                    'amazon_link' => $data['amazon_link'] ?? null,
                    'flipkart_link' => $data['flipkart_link'] ?? null,
                    'meta_title' => $data['meta_title'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                    'meta_keywords' => $data['meta_keywords'] ?? null,
                    'created_id' => $created_id,
                    'created_on' => $created_on,
                    'status' => $status,
                    'product_type' => $product_type
                ];

                log_message('info', 'Products::add - STEP: About to insert product product_code=' . $product_code . ' sku=' . ($productArr['sku_number'] ?? ''));
                log_message('debug', 'Products::add - inserting product_code=' . $product_code);

                // Final uniqueness check before insert (catches race conditions / missed paths)
                if (!empty($productArr['slug']) && $this->productModel->productSlugExists($productArr['slug'])) {
                    return json_error('Slug already exists. Please choose a different slug.', 400);
                }

                $product_id = $this->productModel->insertProduct($productArr);
                
                log_message('debug', 'Products::add - insertProduct returned ID: ' . ($product_id !== false ? $product_id : 'false'));

                // CRITICAL: Check if insert actually succeeded
                if ($product_id === false || $product_id <= 0) {
                    // Check for duplicate slug (MySQL 1062) - return user-friendly error
                    $db = \Config\Database::connect();
                    $dbError = $db->error();
                    $errorCode = $dbError['code'] ?? 0;
                    if ($errorCode == 1062 && !empty($dbError['message']) && (stripos($dbError['message'], 'slug') !== false || stripos($dbError['message'], 'uq_products_slug') !== false)) {
                        return json_error('Slug already exists. Please choose a different slug.', 400);
                    }
                    
                    // Get model errors for detailed error message
                    $modelErrors = $this->productModel->errors();
                    $errorMessage = 'Failed to save product to database';
                    
                    if (!empty($modelErrors)) {
                        $errorMessage .= ': ' . implode(', ', $modelErrors);
                    }
                    
                    if (!empty($dbError['message'])) {
                        $errorMessage .= ' (DB Error: ' . $dbError['message'] . ')';
                    }
                    
                    log_message('error', 'Products::add - ' . $errorMessage);
                    log_message('error', 'Products::add - Product data attempted: ' . json_encode($productArr));
                    
                    return json_error($errorMessage, 500);
                }

                if ($product_id > 0) {
                    // Insert Product Images
                    $projectimageArr = [];
                    
                    // Handle image uploads using CI4's file handling
                    $files = $this->request->getFiles();
                    if (isset($files['image'])) {
                        $upload_dir = FCPATH . 'assets/productimages/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Handle both single file and multiple files
                        $imageFiles = $files['image'];
                        if (!is_array($imageFiles)) {
                            $imageFiles = [$imageFiles];
                        }
                        
                        // Get the current max display_order before processing files
                        $currentMaxOrder = $this->productModel->getCurrentMaxImageDisplayOrder($product_id);
                        $startOrder = ($currentMaxOrder === null || $currentMaxOrder < 0) ? 0 : $currentMaxOrder + 1;
                        
                        foreach ($imageFiles as $pkey => $file) {
                            if ($file->isValid() && !$file->hasMoved()) {
                                $file_ext = $file->getClientExtension();
                                if (empty($file_ext)) {
                                    $mime_type = $file->getClientMimeType();
                                    $mime_to_ext = [
                                        'image/jpeg' => 'jpg',
                                        'image/jpg' => 'jpg',
                                        'image/png' => 'png',
                                        'image/gif' => 'gif',
                                        'image/webp' => 'webp'
                                    ];
                                    $file_ext = $mime_to_ext[$mime_type] ?? 'jpg';
                                }
                                
                                $unique_filename = 'product_' . $product_id . '_' . time() . '_' . $pkey . '.' . $file_ext;
                                
                                if ($file->move($upload_dir, $unique_filename)) {
                                    log_message('debug', 'File moved successfully: ' . $unique_filename);
                                    // Assign display_order: first image in batch gets startOrder, subsequent get sequential
                                    $display_order = $startOrder + $pkey;
                                    $projectimageArr[] = [
                                        'image' => $unique_filename,
                                        'product_id' => (int)$product_id,
                                        'display_order' => $display_order,
                                        'created_on' => $created_on,
                                        'created_id' => $created_id,
                                        'status' => $status
                                    ];
                                } else {
                                    log_message('error', 'Failed to move uploaded file: ' . $file->getName());
                                }
                            }
                        }
                    }

                    // Insert images into database
                    if (count($projectimageArr) > 0) {
                        log_message('debug', 'Attempting to insert ' . count($projectimageArr) . ' images for product_id: ' . $product_id);
                        foreach ($projectimageArr as $ppvalue) {
                            $image_result = $this->productModel->insertProductImage($ppvalue);
                            if ($image_result) {
                                log_message('debug', 'Image inserted successfully with ID: ' . $image_result);
                            } else {
                                log_message('error', 'Failed to insert image into database for product_id: ' . $product_id);
                            }
                        }
                    }

                    // Handle product descriptions - save ALL descriptions to product_descriptions table
                    $descriptions = $data['descriptions'] ?? null;
                    if (is_string($descriptions)) {
                        $descriptions = json_decode($descriptions, true);
                    }
                    
                    if (!empty($descriptions) && is_array($descriptions)) {
                        // Save descriptions from new array format (short, long, technical, seo)
                        foreach ($descriptions as $desc) {
                            if (isset($desc['type']) && isset($desc['content']) && !empty(trim($desc['content']))) {
                                $this->productModel->upsertProductDescription(
                                    $product_id,
                                    $desc['type'],
                                    trim($desc['content']),
                                    $desc['language_code'] ?? 'en',
                                    $created_id
                                );
                            }
                        }
                    }
                    
                    // Also migrate legacy fields to product_descriptions table if not already in descriptions array
                    if (empty($descriptions) || !is_array($descriptions)) {
                        if (!empty($short_description) && trim($short_description) != '') {
                            $this->productModel->upsertProductDescription($product_id, 'short', trim($short_description), 'en', $created_id);
                        }
                        if (!empty($product_description) && trim($product_description) != '') {
                            $this->productModel->upsertProductDescription($product_id, 'long', trim($product_description), 'en', $created_id);
                        }
                    } else {
                        // Check if short/long are in descriptions array, if not, migrate from legacy fields
                        $hasShort = false;
                        $hasLong = false;
                        foreach ($descriptions as $desc) {
                            if (isset($desc['type']) && $desc['type'] === 'short') $hasShort = true;
                            if (isset($desc['type']) && $desc['type'] === 'long') $hasLong = true;
                        }
                        if (!$hasShort && !empty($short_description) && trim($short_description) != '') {
                            $this->productModel->upsertProductDescription($product_id, 'short', trim($short_description), 'en', $created_id);
                        }
                        if (!$hasLong && !empty($product_description) && trim($product_description) != '') {
                            $this->productModel->upsertProductDescription($product_id, 'long', trim($product_description), 'en', $created_id);
                        }
                    }

                    // Handle product information (title/description for Product Detail API)
                    $product_info = $data['product_info'] ?? null;
                    if (is_string($product_info)) {
                        $product_info = json_decode($product_info, true);
                    }
                    if (!empty($product_info) && is_array($product_info)) {
                        $this->productModel->bulkUpdateProductInformation($product_id, $product_info);
                    }

                    // Handle new product enhancements
                    // Color Variants
                    $colors = $data['colors'] ?? null;
                    if (is_string($colors)) {
                        $colors = json_decode($colors, true);
                    }
                    if (!empty($colors) && is_array($colors)) {
                        $this->productModel->bulkUpdateProductColorVariants($product_id, $colors);
                    }

                    // Specifications
                    $specifications = $data['specifications'] ?? null;
                    if (is_string($specifications)) {
                        $specifications = json_decode($specifications, true);
                    }
                    if (!empty($specifications) && is_array($specifications)) {
                        $this->productModel->bulkUpdateProductSpecifications($product_id, $specifications);
                    }

                    // Specification Images
                    $specification_images = $data['specification_images'] ?? $data['specificationImages'] ?? null;
                    if (is_string($specification_images)) {
                        $specification_images = json_decode($specification_images, true);
                    }
                    if (!empty($specification_images) && is_array($specification_images)) {
                        $this->productModel->bulkUpdateProductSpecificationImages($product_id, $specification_images);
                    }

                    // Why Choose
                    $why_choose = $data['why_choose'] ?? $data['whyChoose'] ?? null;
                    if (is_string($why_choose)) {
                        $why_choose = json_decode($why_choose, true);
                    }
                    if (!empty($why_choose) && is_array($why_choose)) {
                        $this->productModel->bulkUpdateProductWhyChoose($product_id, $why_choose);
                    }

                    // Disclaimers
                    $disclaimers = $data['disclaimer'] ?? null;
                    if (is_string($disclaimers)) {
                        $disclaimers = json_decode($disclaimers, true);
                    }
                    if (!empty($disclaimers) && is_array($disclaimers)) {
                        $this->productModel->bulkUpdateProductDisclaimers($product_id, $disclaimers);
                    }

                    // Brochures: process uploaded PDF file(s) to assets/brochures/, then merge with any JSON brochure data
                    $brochureFromUpload = $this->processBrochureUploads($product_id);
                    $brochures = $data['brochure'] ?? null;
                    if (is_string($brochures)) {
                        $brochures = json_decode($brochures, true);
                    }
                    $brochures = is_array($brochures) ? array_merge($brochureFromUpload, $brochures) : $brochureFromUpload;
                    if (!empty($brochures)) {
                        $this->productModel->bulkUpdateProductBrochures($product_id, $brochures);
                    }

                    // CRITICAL: Verify product was actually saved to database before returning success
                    // Use direct query instead of getProductDetails to avoid JOIN issues
                    // CRITICAL FIX: Get database connection properly (CodeIgniter 4)
                    $db = \Config\Database::connect();
                    $verifyBuilder = $db->table('products');
                    $verifyBuilder->where('id', $product_id);
                    $verifyResult = $verifyBuilder->get()->getResultArray();
                    
                    if (empty($verifyResult)) {
                        log_message('error', 'Products::add - Product insert returned ID ' . $product_id . ' but product not found in database');
                        log_message('error', 'Products::add - Database query returned empty result');
                        return json_error('Product was not saved to database. Please try again.', 500);
                    }

                    // Fallback so you always see something in server error log (PuTTY: tail -f /var/log/apache2/error.log or nginx error_log)
                    log_message('info', 'Products::add - STEP: Product saved to DB product_id=' . $product_id . ', calling EasyEcom sync');
                    error_log('Products::add - Product created ID=' . $product_id . ', about to call EasyEcomSyncService::fire');

                    $syncEnabled = filter_var(env('EASYECOM_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
                    $syncResult = EasyEcomSyncService::fire(fn ($s) => $s->syncProduct((int) $product_id, $productArr));
                    // EasyEcom inventory sync temporarily disabled — product sync only
                    // EasyEcomSyncService::fire(fn ($s) => $s->syncProductInventory((int) $product_id, ['quantity' => (int) ($productArr['stock_quantity'] ?? 0)]));

                    log_message('info', 'Products::add - STEP: EasyEcom sync returned success=' . ($syncResult['success'] ? 'true' : 'false') . ' product_id=' . $product_id . ' easyecom_product_id=' . ($syncResult['data']['easyecom_product_id'] ?? 'null'));
                    error_log('Products::add - EasyEcomSyncService::fire finished for product_id=' . $product_id);

                    // CHECKPOINT: If EasyEcom sync is enabled and did not return a product ID, do not keep the product — rollback and throw error
                    if ($syncEnabled) {
                        $eeId = $syncResult['data']['easyecom_product_id'] ?? null;
                        if (!$syncResult['success'] || $eeId === null || $eeId === '') {
                            log_message('error', 'Products::add - CHECKPOINT FAILED: EasyEcom product ID is null or sync failed. product_id=' . $product_id . ' success=' . ($syncResult['success'] ? '1' : '0') . ' message=' . ($syncResult['message'] ?? '') . ' — rolling back (soft-deleting product)');
                            $this->productModel->updateProduct($product_id, ['status' => 'DELETED']);
                            return json_error('Product was not saved: EasyEcom did not return a product ID. ' . ($syncResult['message'] ?? 'Check application logs for details.'), 500);
                        }
                        log_message('info', 'Products::add - CHECKPOINT passed: easyecom_product_id=' . $eeId . ' product_id=' . $product_id);
                    }

                    log_message('info', 'Products::add - Product created ID=' . $product_id);
                    return json_success(['id' => $product_id], 'Product has been added successfully');
                } else {
                    // This should never be reached due to check at line 1234, but keep as safety net
                    log_message('error', 'Products::add - Reached else block with product_id: ' . ($product_id ?: 'false'));
                    return json_error('Failed to add product', 500);
                }
            } catch (\Exception $e) {
                // Log the error for debugging
                log_message('error', 'Products::add - Exception: ' . $e->getMessage());
                log_message('error', 'Products::add - Stack trace: ' . $e->getTraceAsString());
                return json_error('Error adding product: ' . $e->getMessage(), 500);
            }
        } else {
            // Handle GET (return form data)
            $cat_list = $this->productModel->getProductCategoryList('ACTIVE');
            $brand_list = $this->productModel->getProductBrandList('ACTIVE');
            $unit_list = $this->productModel->getUnitList('ACTIVE');

            return json_success([
                'categories' => $cat_list,
                'brands' => $brand_list,
                'units' => $unit_list
            ]);
        }
    }

    public function edit($pId = null)
    {
        // Set Content-Type to JSON early to prevent Debug Toolbar HTML injection
        $this->response->setContentType('application/json');
        
        // Get ID from route parameter or URI segment (backward compatibility)
        if (empty($pId)) {
            $pId = $this->request->getUri()->getSegment(3);
        }

        if (empty($pId)) {
            return json_error('Product ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            try {
                log_message('info', 'Products::edit - Starting update for product_id: ' . $pId);
                
                // Check if this is a multipart/form-data request (has files)
                $isFormData = !empty($this->request->getFiles());
                
                // Get JSON input first (for API requests), or use POST data for form submissions
                $json = null;
                if (!$isFormData) {
                    $rawBody = $this->request->getBody();
                    log_message('info', 'Products::edit - Raw request body length: ' . strlen($rawBody));
                    
                    $json = $this->request->getJSON(true);
                    if ($json === null) {
                        // Try to parse manually if getJSON failed
                        $rawBody = trim($rawBody);
                        if (!empty($rawBody)) {
                            $json = json_decode($rawBody, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                log_message('error', 'Products::edit - JSON decode error: ' . json_last_error_msg());
                                $json = null;
                            }
                        }
                    }
                    
                    if ($json === null) {
                        log_message('warning', 'Products::edit - No JSON data received, will use POST data');
                    } else {
                        log_message('info', 'Products::edit - JSON data received, keys: ' . implode(', ', array_keys($json)));
                    }
                } else {
                    log_message('info', 'Products::edit - FormData request detected (has files)');
                }
                
                // Use JSON data if available, otherwise fall back to POST
                // Handle category_id: can be null (to clear), 0 (to clear), or a positive number
                $category_id_raw = $json['category_id'] ?? $this->request->getPost('category_id');
                $category_id = ($category_id_raw === null || $category_id_raw === '' || $category_id_raw === '0' || $category_id_raw === 0) ? null : $category_id_raw;
                $brand_id = $json['brand_id'] ?? $this->request->getPost('brand_id');
                $product_code = $json['product_code'] ?? $this->request->getPost('product_code');
                $status = $json['status'] ?? $this->request->getPost('status');

                // If only status is provided (and no other required fields), allow partial update
                $hasOnlyStatus = false;
                
                // Check if only status field is provided in JSON
                if ($json && is_array($json)) {
                    $jsonKeys = array_keys($json);
                    // Check if JSON only contains 'status' key
                    $hasOnlyStatus = count($jsonKeys) === 1 && isset($json['status']) && !empty($status);
                    log_message('info', 'Products::edit - Status-only update check: ' . ($hasOnlyStatus ? 'YES' : 'NO') . ', JSON keys: ' . implode(', ', $jsonKeys));
                } elseif (!$isFormData && !empty($status)) {
                    // Check POST data if JSON is not available
                    $postData = $this->request->getPost();
                    if (is_array($postData)) {
                        $postKeys = array_keys($postData);
                        $hasOnlyStatus = count($postKeys) === 1 && isset($postData['status']) && !empty($status);
                        log_message('info', 'Products::edit - Status-only update check (POST): ' . ($hasOnlyStatus ? 'YES' : 'NO') . ', POST keys: ' . implode(', ', $postKeys));
                    }
                }

                if ($hasOnlyStatus) {
                    log_message('info', 'Products::edit - Processing status-only update for product_id: ' . $pId . ', status: ' . $status);
                    $pData = ['status' => $status];
                    $result = $this->productModel->updateProduct($pId, $pData);
                    
                    if ($result == true) {
                        log_message('info', 'Products::edit - Status update successful');
                        EasyEcomSyncService::fire(fn ($s) => $s->updateProduct((int) $pId));
                        return json_success(null, 'Product status has been updated successfully');
                    } else {
                        log_message('error', 'Products::edit - Status update failed');
                        return json_error('Failed to update product status', 500);
                    }
                }

                // For full updates, product_code is required, category_id is optional
                if (empty($product_code)) {
                    return json_error('Product Code is required', 400);
                }
                
                // If category_id is provided, validate it exists (optional)
                $final_category_id = null;
                if (!empty($category_id) && $category_id > 0) {
                    $category_check = $this->productModel->getProductCategoryList('ACTIVE');
                    $category_exists = false;
                    foreach ($category_check as $cat) {
                        if ($cat['id'] == $category_id) {
                            $category_exists = true;
                            $final_category_id = (int)$category_id;
                            break;
                        }
                    }
                    if (!$category_exists) {
                        $category_check_inactive = $this->productModel->getProductCategoryList('INACTIVE');
                        foreach ($category_check_inactive as $cat) {
                            if ($cat['id'] == $category_id) {
                                $category_exists = true;
                                $final_category_id = (int)$category_id;
                                break;
                            }
                        }
                    }
                    if (!$category_exists && $category_id !== null && $category_id !== '' && $category_id !== 0) {
                        return json_error('Selected category does not exist or has been deleted', 400);
                    }
                }

                $valids_pcode = $this->productModel->productCode_edit($pId, $product_code);
                
                if (isset($valids_pcode) && count($valids_pcode) > 0) {
                    return json_error('Product code already exists', 400);
                }

                // Build update array with only provided fields (partial update support)
                $pArr = [];
                
                // Get current product data to preserve fields not being updated
                $currentProduct = $this->productModel->getProductDetails($pId);
                if (empty($currentProduct)) {
                    return json_error('Product not found', 404);
                }
                $current = $currentProduct;
                
                // Only include fields that are explicitly provided in the request
                // Category handling
                if (isset($json['category_id']) || $this->request->getPost('category_id') !== null) {
                    $pArr['category_id'] = $final_category_id; // Can be NULL to clear
                }
                
                // Brand
                if (isset($json['brand_id']) || $this->request->getPost('brand_id') !== null) {
                    $brand_id_val = $json['brand_id'] ?? $this->request->getPost('brand_id');
                    if ($brand_id_val !== null) {
                        $pArr['brand_id'] = (int)$brand_id_val;
                    }
                }
                
                // Product code
                if (isset($json['product_code']) || $this->request->getPost('product_code') !== null) {
                    $pArr['product_code'] = $product_code;
                }
                
                // Product name
                if (isset($json['product_name']) || $this->request->getPost('product_name') !== null) {
                    $product_name = $json['product_name'] ?? $this->request->getPost('product_name');
                    if ($product_name !== null) {
                        $pArr['product_name'] = $product_name;
                    }
                }
                
                // Price fields - only update if provided
                $mrp_provided = isset($json['mrp']) || isset($json['product_price']) || $this->request->getPost('mrp') !== null || $this->request->getPost('product_price') !== null;
                $discount_provided = isset($json['discount']) || isset($json['discount_price']) || $this->request->getPost('discount') !== null || $this->request->getPost('discount_price') !== null;
                $discount_percent_provided = isset($json['discount_off_inpercent']) || $this->request->getPost('discount_off_inpercent') !== null;
                $gst_rate_provided = isset($json['gst_rate']) || $this->request->getPost('gst_rate') !== null;
                
                if ($mrp_provided || $discount_provided || $discount_percent_provided || $gst_rate_provided) {
                    // Get values (use existing if not provided)
                    $mrp = $mrp_provided ? floatval($json['mrp'] ?? $json['product_price'] ?? $this->request->getPost('mrp') ?? $this->request->getPost('product_price') ?? 0) : floatval($current['mrp'] ?? $current['product_price'] ?? 0);
                    $discount = $discount_provided ? floatval($json['discount'] ?? $json['discount_price'] ?? $this->request->getPost('discount') ?? $this->request->getPost('discount_price') ?? 0) : floatval($current['discount'] ?? $current['discount_price'] ?? 0);
                    $discount_off_inpercent = $discount_percent_provided ? floatval($json['discount_off_inpercent'] ?? $this->request->getPost('discount_off_inpercent') ?? 0) : floatval($current['discount_off_inpercent'] ?? 0);
                    $gst_rate = $gst_rate_provided ? floatval($json['gst_rate'] ?? $this->request->getPost('gst_rate') ?? 0) : floatval($current['gst_rate'] ?? 0);
                    
                    // Calculate sale_price and final_price using centralized pricing helper
                    $pricing = calculate_all_prices($mrp, $discount, $discount_off_inpercent, $gst_rate);
                    
                    $pArr['mrp'] = $pricing['mrp'];
                    $pArr['discount'] = $pricing['discount'];
                    $pArr['discount_off_inpercent'] = $pricing['discount_off_inpercent'];
                    $pArr['sale_price'] = $pricing['sale_price'];
                    $pArr['final_price'] = $pricing['final_price'];
                    $pArr['product_price'] = $mrp; // Legacy field
                    $pArr['discount_price'] = $discount; // Legacy field
                    if ($gst_rate_provided) {
                        $pArr['gst_rate'] = $gst_rate;
                    }
                }
                
                // Unit fields
                if (isset($json['unit_id']) || $this->request->getPost('unit_id') !== null) {
                    $unit_id = isset($json['unit_id']) ? (int)$json['unit_id'] : ($this->request->getPost('unit_id') !== null ? (int)$this->request->getPost('unit_id') : null);
                    $unit_name = null;
                    if ($unit_id !== null && $unit_id > 0) {
                        $unitDetails = $this->productModel->getUnitDetails('ACTIVE', $unit_id);
                        $unit_name = (!empty($unitDetails) && isset($unitDetails[0]['unit_name']) && $unitDetails[0]['unit_name'] != '') ? $unitDetails[0]['unit_name'] : null;
                        $pArr['unit_id'] = $unit_id;
                        $pArr['unit_name'] = $unit_name;
                    } else {
                        // Explicitly set to null if unit_id is 0 or negative
                        $pArr['unit_id'] = null;
                        $pArr['unit_name'] = null;
                    }
                }
                
                // SKU number
                if (isset($json['sku_number']) || $this->request->getPost('sku_number') !== null) {
                    $sku_number = $json['sku_number'] ?? $this->request->getPost('sku_number');
                    if ($sku_number !== null) {
                        $pArr['sku_number'] = $sku_number;
                    }
                }
                
                // Stock management using centralized helper
                if (isset($json['stock_quantity']) || isset($json['stock']) || $this->request->getPost('stock_quantity') !== null || $this->request->getPost('stock') !== null) {
                    $stock_quantity_raw = $json['stock_quantity'] ?? $json['stock'] ?? $this->request->getPost('stock_quantity') ?? $this->request->getPost('stock');
                    $stock_validation = validate_stock_quantity($stock_quantity_raw, 0);
                    if ($stock_validation['valid']) {
                        $stock_data = prepare_stock_data($stock_validation['value']);
                        $pArr['stock_quantity'] = $stock_data['stock_quantity'];
                        $pArr['in_stock'] = $stock_data['in_stock'];
                    }
                } elseif (isset($json['in_stock']) || $this->request->getPost('in_stock') !== null) {
                    // Only in_stock provided (legacy support)
                    $in_stock_val = $json['in_stock'] ?? $this->request->getPost('in_stock');
                    if ($in_stock_val !== null) {
                        $pArr['in_stock'] = $in_stock_val;
                    }
                }
                
                // Product type
                if (isset($json['product_type']) || $this->request->getPost('product_type') !== null) {
                    $product_type = $json['product_type'] ?? ($this->request->getPost('product_type') ?? 'NA');
                    if ($product_type !== null) {
                        $pArr['product_type'] = $product_type;
                    }
                }
                
                // Status
                if (!empty($status)) {
                    $pArr['status'] = $status;
                }
                
                // Handle new product fields
                // Slug handling - always auto-generate from product_name if product_name is provided
                // Slug can be manually overridden if explicitly provided
                $new_product_name = null;
                if (isset($json['product_name']) || $this->request->getPost('product_name') !== null) {
                    $new_product_name = $json['product_name'] ?? $this->request->getPost('product_name');
                    if ($new_product_name !== null && $new_product_name !== '') {
                        $pArr['product_name'] = $new_product_name;
                    }
                }
                
                // Determine if we should auto-generate slug
                $should_auto_generate_slug = false;
                $slug_provided = false;
                
                if (isset($json['slug']) || $this->request->getPost('slug') !== null) {
                    $provided_slug = $json['slug'] ?? $this->request->getPost('slug');
                    // Check if slug is actually provided (not empty/null/undefined)
                    if ($provided_slug !== null && $provided_slug !== '' && $provided_slug !== 'null' && $provided_slug !== 'undefined') {
                        $slug_provided = true;
                        $provided_slug = strtolower(trim($provided_slug));
                        // Validate slug uniqueness (exclude current product)
                        if ($this->productModel->productSlugExists($provided_slug, $pId)) {
                            return json_error('Slug already exists. Please choose a different slug.', 400);
                        }
                        $pArr['slug'] = $provided_slug;
                    } else {
                        // Slug field was provided but is empty/null - should auto-generate
                        $should_auto_generate_slug = true;
                    }
                } else {
                    // Slug not provided at all - check if product_name is provided or changed
                    if ($new_product_name !== null && $new_product_name !== '') {
                        // Check if product name changed
                        if ($new_product_name !== $current['product_name']) {
                            $should_auto_generate_slug = true;
                        } elseif (empty($current['slug'])) {
                            // Product name not changed but slug is empty - generate it
                            $should_auto_generate_slug = true;
                        }
                    } elseif (empty($current['slug']) && !empty($current['product_name'])) {
                        // No new product_name provided, but current product has no slug - generate from current name
                        $should_auto_generate_slug = true;
                        $new_product_name = $current['product_name'];
                    }
                }
                
                // Auto-generate slug from product_name (same rule as add: if exists, reject)
                if ($should_auto_generate_slug && !empty($new_product_name)) {
                    $slug = strtolower(trim($new_product_name));
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                    $slug = preg_replace('/^-+|-+$/', '', $slug);
                    if (!empty($slug) && $this->productModel->productSlugExists($slug, $pId)) {
                        return json_error('A product with this slug already exists. Please use a different product name or slug.', 400);
                    }
                    $pArr['slug'] = $slug;
                    log_message('info', 'Products::edit - Auto-generated slug: ' . $slug . ' from product_name: ' . $new_product_name . ' for product_id: ' . $pId);
                }
                
                if (isset($json['model']) || $this->request->getPost('model') !== null) {
                    $model = $json['model'] ?? $this->request->getPost('model');
                    if ($model !== null) {
                        $pArr['model'] = $model;
                    }
                }
                
                if (isset($json['subcategory_id']) || $this->request->getPost('subcategory_id') !== null) {
                    $subcategory_id = $json['subcategory_id'] ?? $this->request->getPost('subcategory_id');
                    $subcategory_id = ($subcategory_id === null || $subcategory_id === '' || $subcategory_id === '0' || $subcategory_id === 0) ? null : (int)$subcategory_id;
                    if ($subcategory_id > 0) {
                        $eff_category_id = $final_category_id ?? ($current['category_id'] ?? null);
                        if ($eff_category_id > 0) {
                            $subcats = $this->productModel->getProductSubcategoryList($eff_category_id, 'ACTIVE');
                            $valid_subcat = false;
                            foreach ($subcats as $sc) {
                                if ((int)$sc['id'] === $subcategory_id) {
                                    $valid_subcat = true;
                                    break;
                                }
                            }
                            if (!$valid_subcat) {
                                return json_error('Selected subcategory does not belong to the selected category', 400);
                            }
                        }
                    }
                    $pArr['subcategory_id'] = $subcategory_id;
                }
                
                if (isset($json['weight']) || $this->request->getPost('weight') !== null) {
                    $weight = $json['weight'] ?? $this->request->getPost('weight');
                    $pArr['weight'] = ($weight !== null && $weight !== '') ? (is_numeric($weight) ? floatval($weight) : null) : null;
                }
                
                if (isset($json['dimensions']) || $this->request->getPost('dimensions') !== null) {
                    $dimensions = $json['dimensions'] ?? $this->request->getPost('dimensions');
                    $pArr['dimensions'] = $dimensions !== null ? $dimensions : null;
                }
                
                if (isset($json['visibility']) || $this->request->getPost('visibility') !== null) {
                    $visibility = $json['visibility'] ?? $this->request->getPost('visibility');
                    if (in_array($visibility, ['PUBLIC', 'HIDDEN'])) {
                        $pArr['visibility'] = $visibility;
                    }
                }
                
                if (isset($json['featured']) || $this->request->getPost('featured') !== null) {
                    $featured = $json['featured'] ?? $this->request->getPost('featured');
                    if (in_array($featured, ['YES', 'NO'])) {
                        $pArr['featured'] = $featured;
                    }
                }
                if (isset($json['home_display_order']) || $this->request->getPost('home_display_order') !== null) {
                    $home_display_order = $json['home_display_order'] ?? $this->request->getPost('home_display_order');
                    if (is_numeric($home_display_order)) {
                        $pArr['home_display_order'] = (int)$home_display_order;
                    }
                }
                if (isset($json['amazon_link']) || $this->request->getPost('amazon_link') !== null) {
                    $amazon_link = $json['amazon_link'] ?? $this->request->getPost('amazon_link');
                    $pArr['amazon_link'] = $amazon_link !== null ? $amazon_link : null;
                }
                
                if (isset($json['flipkart_link']) || $this->request->getPost('flipkart_link') !== null) {
                    $flipkart_link = $json['flipkart_link'] ?? $this->request->getPost('flipkart_link');
                    $pArr['flipkart_link'] = $flipkart_link !== null ? $flipkart_link : null;
                }
                
                if (isset($json['meta_title']) || $this->request->getPost('meta_title') !== null) {
                    $meta_title = $json['meta_title'] ?? $this->request->getPost('meta_title');
                    $pArr['meta_title'] = $meta_title !== null ? $meta_title : null;
                }
                
                if (isset($json['meta_description']) || $this->request->getPost('meta_description') !== null) {
                    $meta_description = $json['meta_description'] ?? $this->request->getPost('meta_description');
                    $pArr['meta_description'] = $meta_description !== null ? $meta_description : null;
                }
                
                if (isset($json['meta_keywords']) || $this->request->getPost('meta_keywords') !== null) {
                    $meta_keywords = $json['meta_keywords'] ?? $this->request->getPost('meta_keywords');
                    $pArr['meta_keywords'] = $meta_keywords !== null ? $meta_keywords : null;
                }

                log_message('info', 'Products::edit - Attempting to update product ID: ' . $pId);
                
                $result = $this->productModel->updateProduct($pId, $pArr);
                
                // CRITICAL: Check if update actually succeeded
                if ($result === false) {
                    // Get database errors for detailed error message
                    $db = \Config\Database::connect();
                    $dbError = $db->error();
                    $errorMessage = 'Failed to update product in database';
                    
                    if (!empty($dbError['message'])) {
                        $errorMessage .= ': ' . $dbError['message'];
                        log_message('error', 'Products::edit - Database error: ' . $dbError['message']);
                    }
                    
                    log_message('error', 'Products::edit - Product update failed. Product ID: ' . $pId . ', Update data: ' . json_encode($pArr));
                    return json_error($errorMessage, 500);
                }

                if ($result == true) {
                    // Handle product descriptions - save ALL descriptions to product_descriptions table
                    $descriptions = $json['descriptions'] ?? ($this->request->getPost('descriptions') ? json_decode($this->request->getPost('descriptions'), true) : null);
                    
                    // Get user ID from session
                    $session = \Config\Services::session();
                    $checkuservars = $session->get();
                    $createdId = $checkuservars['userid'] ?? null;
                    $created_on = date('Y-m-d H:i:s');
                    
                    if (!empty($descriptions) && is_array($descriptions)) {
                        // Save descriptions from new array format (short, long, technical, seo)
                        foreach ($descriptions as $desc) {
                            if (isset($desc['type']) && isset($desc['content']) && !empty(trim($desc['content']))) {
                                $this->productModel->upsertProductDescription(
                                    $pId,
                                    $desc['type'],
                                    trim($desc['content']),
                                    $desc['language_code'] ?? 'en',
                                    $createdId
                                );
                            }
                        }
                    }
                    
                    // Also migrate legacy fields to product_descriptions table if not already in descriptions array
                    if (empty($descriptions) || !is_array($descriptions)) {
                        $short_description = $json['short_description'] ?? $this->request->getPost('short_description');
                        $product_description = $json['product_description'] ?? $this->request->getPost('product_description');
                        
                        if ($short_description !== null && trim($short_description) != '') {
                            $this->productModel->upsertProductDescription($pId, 'short', trim($short_description), 'en', $createdId);
                        }
                        if ($product_description !== null && trim($product_description) != '') {
                            $this->productModel->upsertProductDescription($pId, 'long', trim($product_description), 'en', $createdId);
                        }
                    } else {
                        // Check if short/long are in descriptions array, if not, migrate from legacy fields
                        $hasShort = false;
                        $hasLong = false;
                        foreach ($descriptions as $desc) {
                            if (isset($desc['type']) && $desc['type'] === 'short') $hasShort = true;
                            if (isset($desc['type']) && $desc['type'] === 'long') $hasLong = true;
                        }
                        
                        $short_description = $json['short_description'] ?? $this->request->getPost('short_description');
                        $product_description = $json['product_description'] ?? $this->request->getPost('product_description');
                        
                        if (!$hasShort && $short_description !== null && trim($short_description) != '') {
                            $this->productModel->upsertProductDescription($pId, 'short', trim($short_description), 'en', $createdId);
                        }
                        if (!$hasLong && $product_description !== null && trim($product_description) != '') {
                            $this->productModel->upsertProductDescription($pId, 'long', trim($product_description), 'en', $createdId);
                        }
                    }
                    
                    // Handle image uploads if provided
                    $projectimageArr = [];
                    $files = $this->request->getFiles();
                    if (isset($files['image'])) {
                        // Mark existing images as deleted only if new images are being uploaded
                        $pImage = ['status' => 'DELETED'];
                        $this->productModel->updateProductImage($pId, $pImage);

                        $upload_dir = FCPATH . 'assets/productimages/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        // Handle both single file and multiple files
                        $imageFiles = $files['image'];
                        if (!is_array($imageFiles)) {
                            $imageFiles = [$imageFiles];
                        }

                        // Get the current max display_order before processing files
                        $currentMaxOrder = $this->productModel->getCurrentMaxImageDisplayOrder($pId);
                        $startOrder = ($currentMaxOrder === null || $currentMaxOrder < 0) ? 0 : $currentMaxOrder + 1;

                        foreach ($imageFiles as $pkey => $file) {
                            if ($file->isValid() && !$file->hasMoved()) {
                                $file_ext = $file->getClientExtension();
                                if (empty($file_ext)) {
                                    $mime_type = $file->getClientMimeType();
                                    $mime_to_ext = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                                    $file_ext = $mime_to_ext[$mime_type] ?? 'jpg';
                                }
                                
                                $unique_filename = 'product_' . $pId . '_' . time() . '_' . $pkey . '.' . $file_ext;
                                $upload_path = $upload_dir . $unique_filename;

                                if ($file->move($upload_dir, $unique_filename)) {
                                    // Assign display_order: first image in batch gets startOrder, subsequent get sequential
                                    $display_order = $startOrder + $pkey;
                                    $projectimageArr[] = [
                                        'image' => $unique_filename,
                                        'product_id' => (int)$pId,
                                        'display_order' => $display_order,
                                        'created_on' => $created_on,
                                        'created_id' => $createdId,
                                        'status' => $status ?? 'ACTIVE'
                                    ];
                                }
                            }
                        }

                        if (count($projectimageArr) > 0) {
                            foreach ($projectimageArr as $ppvalue) {
                                $this->productModel->insertProductImage($ppvalue);
                            }
                        }
                    }

                    // Handle product information (title/description for Product Detail API)
                    $product_info = $json['product_info'] ?? ($this->request->getPost('product_info') ? json_decode($this->request->getPost('product_info'), true) : null);
                    if (is_string($product_info)) {
                        $product_info = json_decode($product_info, true);
                    }
                    if (!empty($product_info) && is_array($product_info)) {
                        $this->productModel->bulkUpdateProductInformation($pId, $product_info);
                    }

                    // Handle new product enhancements
                    // Color Variants
                    $colors = $json['colors'] ?? ($this->request->getPost('colors') ? json_decode($this->request->getPost('colors'), true) : null);
                    if (!empty($colors) && is_array($colors)) {
                        $this->productModel->bulkUpdateProductColorVariants($pId, $colors);
                    }

                    // Specifications
                    $specifications = $json['specifications'] ?? ($this->request->getPost('specifications') ? json_decode($this->request->getPost('specifications'), true) : null);
                    if (!empty($specifications) && is_array($specifications)) {
                        $this->productModel->bulkUpdateProductSpecifications($pId, $specifications);
                    }

                    // Specification Images
                    $specification_images = $json['specification_images'] ?? $json['specificationImages'] ?? ($this->request->getPost('specification_images') ? json_decode($this->request->getPost('specification_images'), true) : null);
                    if (!empty($specification_images) && is_array($specification_images)) {
                        $this->productModel->bulkUpdateProductSpecificationImages($pId, $specification_images);
                    }

                    // Why Choose
                    $why_choose = $json['why_choose'] ?? $json['whyChoose'] ?? ($this->request->getPost('why_choose') ? json_decode($this->request->getPost('why_choose'), true) : null);
                    if (!empty($why_choose) && is_array($why_choose)) {
                        $this->productModel->bulkUpdateProductWhyChoose($pId, $why_choose);
                    }

                    // Disclaimers
                    $disclaimers = $json['disclaimer'] ?? ($this->request->getPost('disclaimer') ? json_decode($this->request->getPost('disclaimer'), true) : null);
                    if (!empty($disclaimers) && is_array($disclaimers)) {
                        $this->productModel->bulkUpdateProductDisclaimers($pId, $disclaimers);
                    }

                    // Brochures: process uploaded PDF file(s) to assets/brochures/, then merge with any JSON brochure data
                    $brochureFromUpload = $this->processBrochureUploads($pId);
                    $brochureFromJson = $json['brochure'] ?? ($this->request->getPost('brochure') ? json_decode($this->request->getPost('brochure'), true) : null);
                    $brochures = is_array($brochureFromJson) ? array_merge($brochureFromUpload, $brochureFromJson) : $brochureFromUpload;
                    if (!empty($brochures)) {
                        $this->productModel->bulkUpdateProductBrochures($pId, $brochures);
                    }

                    // Verify product was actually updated (check if it still exists and has correct data)
                    $verifyProduct = $this->productModel->getProductDetails($pId);
                    if (empty($verifyProduct)) {
                        log_message('error', 'Products::edit - Product update succeeded but product not found in database (may have been deleted)');
                        return json_error('Product update completed but product not found. It may have been deleted.', 404);
                    }
                    
                    EasyEcomSyncService::fire(fn ($s) => $s->updateProduct((int) $pId));
                    
                    log_message('info', 'Products::edit - Product successfully updated with ID: ' . $pId . ', verified in database');
                    return json_success(null, 'Product has been updated successfully');
                } else {
                    log_message('error', 'Products::edit - Product update returned false');
                    return json_error('Failed to update product', 500);
                }
            } catch (\Exception $e) {
                log_message('error', 'Product update error: ' . $e->getMessage());
                log_message('error', 'Stack trace: ' . $e->getTraceAsString());
                return json_error('Error updating product: ' . $e->getMessage(), 500);
            }
        } else {
            // Handle GET (retrieve)
            log_message('info', 'Products::edit GET - Retrieving product details for ID: ' . $pId . ' (type: ' . gettype($pId) . ')');
            
            $cat_list = $this->productModel->getProductCategoryList('ACTIVE');
            $brand_list = $this->productModel->getProductBrandList('ACTIVE');
            $unit_list = $this->productModel->getUnitList('ACTIVE');
            $product_details = $this->productModel->getProductDetails($pId);
            
            if (empty($product_details)) {
                // Check if product exists but is deleted
                $db = \Config\Database::connect();
                $builder = $db->table('products');
                $builder->where('id', $pId);
                $deletedProduct = $builder->get()->getRowArray();
                
                if ($deletedProduct) {
                    log_message('warning', 'Products::edit GET - Product ID ' . $pId . ' exists but has status: ' . ($deletedProduct['status'] ?? 'unknown'));
                } else {
                    log_message('warning', 'Products::edit GET - Product ID ' . $pId . ' does not exist in database');
                }
            } else {
                log_message('info', 'Products::edit GET - Product found: ID ' . $pId . ', Status: ' . ($product_details['status'] ?? 'unknown'));
            }
            $product_img_details = $this->productModel->getProductImageDetails($pId);

            // Get product descriptions (all types including technical and SEO)
            $product_descriptions = $this->productModel->getAllProductDescriptions($pId);
            $product_description_images = $this->productModel->getAllDescriptionImages($pId);

            // Product enhancements (aligned with Product Detail API)
            $product_specifications = $this->productModel->getProductSpecifications($pId);
            $product_specification_images = $this->productModel->getProductSpecificationImages($pId);
            $product_information = $this->productModel->getProductInformation($pId);
            $product_why_choose = $this->productModel->getProductWhyChoose($pId);
            $product_disclaimers = $this->productModel->getProductDisclaimers($pId);
            $product_brochures = $this->productModel->getProductBrochures($pId);

            // Variations structured by type (aligned with Product Detail API)
            $gstRate = isset($product_details['gst_rate']) ? (float)$product_details['gst_rate'] : 0;
            $product_variations = $this->productModel->getProductVariationsStructured($pId, $gstRate);

            if ($product_details) {
                $response = [
                    'product' => $product_details,
                    'images' => $product_img_details,
                    'descriptions' => $product_descriptions,
                    'description_images' => $product_description_images,
                    'specifications' => $product_specifications,
                    'specification_images' => $product_specification_images,
                    'product_info' => $product_information,
                    'why_choose' => $product_why_choose,
                    'disclaimers' => $product_disclaimers,
                    'brochures' => $product_brochures,
                    'variations' => $product_variations,
                    'categories' => $cat_list,
                    'brands' => $brand_list,
                    'units' => $unit_list
                ];
                // Backward compat for Dashboard (expects singular keys)
                $response['disclaimer'] = $product_disclaimers;
                $response['brochure'] = $product_brochures;
                return json_success($response);
            } else {
                return json_error('Product not found', 404);
            }
        }
    }

}
