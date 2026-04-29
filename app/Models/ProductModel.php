<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table = 'products';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    
    protected $allowedFields = [
        'product_name', 'product_code', 'sku_number', 'category_id', 'subcategory_id', 'brand_id',
        'slug', 'model', 'mrp', 'discount', 'discount_off_inpercent', 'sale_price', 'final_price',
        'product_price', 'discount_price', // Legacy fields for backward compatibility
        'stock_quantity', 'in_stock', 'gst_rate', 'unit_id', 'unit_name',
        'product_type', 'home_display_status', 'home_display_order', 'status', 'created_id', 'created_on',
        'weight', 'dimensions', 'visibility', 'featured',
        'amazon_link', 'flipkart_link', 'meta_title', 'meta_description', 'meta_keywords'
    ];
    
    protected $useTimestamps = false;
    
    protected $validationRules = [];
    protected $validationMessages = [];
    
    /**
     * Get absolute URL for an asset path
     * Ensures full URL is returned (e.g., https://admin.khaitan.com/backend/assets/productimages/file.jpg)
     */
    private function getAssetUrl($path)
    {
        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        // Try to get base URL from current request first (most reliable)
        try {
            $request = \Config\Services::request();
            $uri = $request->getUri();
            $scheme = $uri->getScheme() ?: 'http';
            $host = $uri->getHost();
            $port = $uri->getPort();
            
            if ($host && $host !== 'localhost' && $host !== '127.0.0.1') {
                // Production/remote server - construct from request
                $portStr = ($port && $port != 80 && $port != 443) ? ':' . $port : '';
                $baseURL = $scheme . '://' . $host . $portStr;
                
                // Check if request path includes /backend/
                $currentPath = $uri->getPath();
                if (strpos($currentPath, '/backend/') !== false || strpos($currentPath, '/backend') === 0) {
                    // Ensure baseURL includes /backend/
                    if (strpos($baseURL, '/backend') === false) {
                        $baseURL .= '/backend';
                    }
                }
                
                // Ensure trailing slash
                $baseURL = rtrim($baseURL, '/') . '/';
                return $baseURL . $path;
            }
        } catch (\Exception $e) {
            // Request service not available, continue to fallbacks
        }
        
        // Fallback 1: Check for production URL in config or environment
        $config = config('App');
        $baseURL = $config->baseURL ?? '';
        
        // If baseURL is set and is absolute, use it
        if (!empty($baseURL) && preg_match('/^https?:\/\//', $baseURL)) {
            $baseURL = rtrim($baseURL, '/') . '/';
            return $baseURL . $path;
        }
        
        // Fallback 2: Try CodeIgniter's base_url() helper
        $url = base_url($path);
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }
        
        // Fallback 3: Construct from current request (even if localhost)
        try {
            $request = \Config\Services::request();
            $uri = $request->getUri();
            $scheme = $uri->getScheme() ?: 'http';
            $host = $uri->getHost() ?: 'localhost';
            $port = $uri->getPort();
            $portStr = ($port && $port != 80 && $port != 443) ? ':' . $port : '';
            
            // Check if request path includes /backend/
            $currentPath = $uri->getPath();
            $backendPath = '';
            if (strpos($currentPath, '/backend/') !== false || strpos($currentPath, '/backend') === 0) {
                $backendPath = '/backend';
            }
            
            return $scheme . '://' . $host . $portStr . $backendPath . '/' . $path;
        } catch (\Exception $e) {
            // Last resort: use default
            return 'http://localhost/backend/' . $path;
        }
    }
    
    // Category Methods
    public function getProductCategoryList($status = null)
    {
        $builder = $this->db->table('product_category');
        
        if ($status === null) {
            $builder->where('status <>', 'DELETED');
        } else {
            $builder->where('status', $status);
        }
        
        $builder->orderBy('parent_id', 'ASC');
        $builder->orderBy('sequences', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }

    /**
     * Get top-level categories only (parent_id IS NULL).
     * Aligns with API: Category → Subcategory → Product flow.
     */
    public function getProductCategoryListTopLevel($status = null)
    {
        $builder = $this->db->table('product_category');
        if ($status === null) {
            $builder->where('status <>', 'DELETED');
        } else {
            $builder->where('status', $status);
        }
        $builder->where('parent_id IS NULL');
        $builder->orderBy('sequences', 'ASC');
        $builder->orderBy('id', 'ASC');
        return $builder->get()->getResultArray();
    }

    /**
     * Get subcategories for a given parent category.
     * Aligns with API: Subcategory Lists requires category_id.
     */
    public function getProductSubcategoryList($parentId, $status = null)
    {
        if ($parentId === null || (int)$parentId <= 0) {
            return [];
        }
        $builder = $this->db->table('product_category AS PSC');
        $builder->select('PSC.*, PC.category as parent_category, PC.categorykey as parent_categorykey');
        $builder->join('product_category AS PC', 'PSC.parent_id = PC.id', 'left');
        if ($status === null) {
            $builder->where('PSC.status <>', 'DELETED');
        } else {
            $builder->where('PSC.status', $status);
        }
        $builder->where('PSC.parent_id', (int)$parentId);
        $builder->orderBy('PSC.sequences', 'ASC');
        $builder->orderBy('PSC.id', 'ASC');
        return $builder->get()->getResultArray();
    }
    
    public function getProductBrandList($status = null)
    {
        $builder = $this->db->table('product_brand');
        
        if ($status === null) {
            $builder->where('status <>', 'DELETED');
        } else {
            $builder->where('status', $status);
        }
        
        $builder->orderBy('sequences', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productCategoryExists($cat)
    {
        $builder = $this->db->table('product_category');
        $builder->where('category', $cat);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productBrandExists($brand)
    {
        $builder = $this->db->table('product_brand');
        $builder->where('brand', $brand);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productCategory_edit($cid, $cat)
    {
        $builder = $this->db->table('product_category');
        $builder->where('category', $cat);
        $builder->where('id <>', $cid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productBrand_edit($bid, $brand)
    {
        $builder = $this->db->table('product_brand');
        $builder->where('brand', $brand);
        $builder->where('id <>', $bid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getproductCategoryDetails($cid)
    {
        $builder = $this->db->table('product_category');
        $builder->where('id', $cid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getproductBrandDetails($bid)
    {
        $builder = $this->db->table('product_brand');
        $builder->where('id', $bid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function insertProductCategory($dataArr)
    {
        try {
            // Log the data being inserted (for debugging)
            log_message('debug', 'ProductModel::insertProductCategory - Attempting insert with data: ' . json_encode($dataArr));
            
            // Check database connection before insert
            $db = \Config\Database::connect();
            if (!$db->connID) {
                log_message('error', 'ProductModel::insertProductCategory - Database connection failed');
                return false;
            }
            
            // Perform insert
            $result = $this->db->table('product_category')->insert($dataArr);
            
            // Check for database errors
            if (!$result) {
                $dbError = $db->error();
                if (!empty($dbError['message'])) {
                    log_message('error', 'ProductModel::insertProductCategory - Database error: ' . $dbError['message']);
                    log_message('error', 'ProductModel::insertProductCategory - SQL Error Code: ' . ($dbError['code'] ?? 'unknown'));
                } else {
                    log_message('error', 'ProductModel::insertProductCategory - Insert returned false but no database error reported');
                }
                
                log_message('error', 'ProductModel::insertProductCategory - Failed data: ' . json_encode($dataArr));
                return false;
            }
            
            $insertId = $this->db->insertID();
            
            if ($insertId > 0) {
                log_message('info', 'ProductModel::insertProductCategory - Successfully inserted category with ID: ' . $insertId);
                return $insertId;
            } else {
                log_message('error', 'ProductModel::insertProductCategory - Insert succeeded but no insert ID returned');
                return false;
            }
        } catch (\Exception $e) {
            log_message('error', 'ProductModel::insertProductCategory - Exception: ' . $e->getMessage());
            log_message('error', 'ProductModel::insertProductCategory - Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    public function insertProductBrand($dataArr)
    {
        $result = $this->db->table('product_brand')->insert($dataArr);
        
        if ($result) {
            return $this->db->insertID();
        }
        
        return false;
    }
    
    public function updateProductCategory($cid, $dataArr)
    {
        try {
            // Log the data being updated (for debugging)
            log_message('debug', 'ProductModel::updateProductCategory - Attempting update for ID: ' . $cid . ' with data: ' . json_encode($dataArr));
            
            // Check database connection before update
            $db = \Config\Database::connect();
            if (!$db->connID) {
                log_message('error', 'ProductModel::updateProductCategory - Database connection failed');
                return false;
            }
            
            $builder = $this->db->table('product_category');
            $builder->where('id', $cid);
            
            $result = $builder->update($dataArr);
            
            // Check for database errors
            if (!$result) {
                $dbError = $db->error();
                if (!empty($dbError['message'])) {
                    log_message('error', 'ProductModel::updateProductCategory - Database error: ' . $dbError['message']);
                    log_message('error', 'ProductModel::updateProductCategory - SQL Error Code: ' . ($dbError['code'] ?? 'unknown'));
                } else {
                    // Update might return false if no rows were affected (but that's not necessarily an error)
                    log_message('debug', 'ProductModel::updateProductCategory - Update returned false, checking if row exists');
                }
                
                log_message('error', 'ProductModel::updateProductCategory - Failed data for ID ' . $cid . ': ' . json_encode($dataArr));
            } else {
                log_message('info', 'ProductModel::updateProductCategory - Successfully updated category ID: ' . $cid);
            }
            
            return $result;
        } catch (\Exception $e) {
            log_message('error', 'ProductModel::updateProductCategory - Exception: ' . $e->getMessage());
            log_message('error', 'ProductModel::updateProductCategory - Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    public function updateProductBrand($bid, $dataArr)
    {
        $builder = $this->db->table('product_brand');
        $builder->where('id', $bid);
        
        return $builder->update($dataArr);
    }
    
    public function getProductList($status = null)
    {
        $builder = $this->db->table('products as P');
        $builder->select('P.*, COALESCE(PC.category, \'\') as category_name');
        $builder->select('CASE WHEN P.brand_id = 0 OR P.brand_id IS NULL THEN \'\' ELSE COALESCE(PB.brand, \'\') END as brand_name');
        $builder->select('(SELECT PI.image FROM product_image AS PI WHERE PI.product_id = CAST(P.id AS UNSIGNED) AND PI.status <> \'DELETED\' ORDER BY PI.display_order ASC, PI.id ASC LIMIT 1) as primary_image');
        
        $builder->join('product_category AS PC', 'P.category_id = PC.id AND PC.status <> \'DELETED\'', 'left');
        $builder->join('product_brand AS PB', 'P.brand_id = PB.id AND P.brand_id > 0 AND PB.status <> \'DELETED\'', 'left');
        
        if ($status === 'DELETED') {
            $builder->where('P.status', 'DELETED');
        } elseif ($status !== null && $status !== '') {
            $builder->where('P.status', $status);
        } else {
            $builder->where('P.status <>', 'DELETED');
        }
        
        // Display order: every product has home_display_order; no other REST API changes needed for order
        $builder->orderBy('P.home_display_order', 'ASC');
        $builder->orderBy('P.id', 'ASC');
        
        return $builder->get()->getResultArray();
    }

    /**
     * Get products that have no EasyEcom product ID (for backfill migration).
     * Excludes soft-deleted products.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductsWithoutEasyecomId(): array
    {
        $builder = $this->db->table('products');
        $builder->select('id, sku_number, product_name, mrp, sale_price, final_price, product_price, category_id, brand_id');
        $builder->where('easyecom_product_id IS NULL', null, false);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'ASC');
        return $builder->get()->getResultArray();
    }
    
    /**
     * Get products that already have an EasyEcom product ID (for name/inventory sync).
     * Excludes soft-deleted products.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductsWithEasyecomId(): array
    {
        $builder = $this->db->table('products as P');
        $builder->select('P.*, PC.category as category_name, PB.brand as brand_name');
        $builder->join('product_category AS PC', 'P.category_id = PC.id AND PC.status <> \'DELETED\'', 'left');
        $builder->join('product_brand AS PB', 'P.brand_id = PB.id AND P.brand_id > 0 AND PB.status <> \'DELETED\'', 'left');
        $builder->where('P.easyecom_product_id IS NOT NULL', null, false);
        $builder->where('P.status <>', 'DELETED');
        $builder->orderBy('P.id', 'ASC');
        
        $result = $builder->get();
        if ($result === false) {
            return [];
        }
        return $result->getResultArray();
    }

    /**
     * Get product variations that do not have an EasyEcom ID.
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getVariationsWithoutEasyecomId(): array
    {
        $builder = $this->db->table('product_variations AS PV');
        $builder->select('PV.*');
        $builder->join('products AS P', 'PV.product_id = P.id', 'inner');
        $builder->where('PV.easyecom_product_id IS NULL', null, false);
        $builder->where('PV.is_deleted', 0);
        $builder->where('P.status <>', 'DELETED');
        $builder->orderBy('PV.id', 'ASC');
        return $builder->get()->getResultArray();
    }

    /**
     * Get product variations that already have an EasyEcom ID.
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getVariationsWithEasyecomId(): array
    {
        $builder = $this->db->table('product_variations');
        $builder->where('easyecom_product_id IS NOT NULL', null, false);
        $builder->where('is_deleted', 0);
        $builder->orderBy('id', 'ASC');
        return $builder->get()->getResultArray();
    }

    /**
     * Get all stockable SKUs with quantities for EasyEcom stock sync.
     * - Simple products (no variations): products.sku_number, products.stock_quantity
     * - Variable products: product_variations.sku, product_variations.stock_quantity
     *
     * @return array<int, array{sku: string, quantity: int, type: string}> Array of ['sku' => string, 'quantity' => int, 'type' => 'product'|'variation']
     */
    public function getAllStockableSkusWithQuantity(): array
    {
        $result = [];

        // 1. Simple products: products with sku_number that have no active variations
        $subQuery = $this->db->table('product_variations')
            ->select('product_id')
            ->where('is_deleted', 0)
            ->groupBy('product_id');
        $subSql = $subQuery->getCompiledSelect();

        $builder = $this->db->table('products');
        $builder->select('products.sku_number AS sku, products.stock_quantity AS quantity');
        $builder->where('products.status <>', 'DELETED');
        $builder->where('products.sku_number IS NOT NULL');
        $builder->where('products.sku_number !=', '');
        $builder->where("products.id NOT IN ({$subSql})", null, false);
        $rows = $builder->get()->getResultArray();

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $result[] = [
                'sku'      => $sku,
                'quantity' => max(0, (int) ($row['quantity'] ?? 0)),
                'type'     => 'product',
            ];
        }

        // 2. Variations: product_variations with sku
        $builder = $this->db->table('product_variations');
        $builder->select('sku, stock_quantity AS quantity');
        $builder->where('is_deleted', 0);
        $builder->where('sku IS NOT NULL');
        $builder->where('sku !=', '');
        $rows = $builder->get()->getResultArray();

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $result[] = [
                'sku'      => $sku,
                'quantity' => max(0, (int) ($row['quantity'] ?? 0)),
                'type'     => 'variation',
            ];
        }

        return $result;
    }
    
    public function productCodeExists($pcode)
    {
        $builder = $this->db->table('products');
        $builder->where('product_code', $pcode);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productCode_edit($pid, $pcode)
    {
        $builder = $this->db->table('products');
        $builder->where('product_code', $pcode);
        $builder->where('id <>', $pid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    /**
     * Check if a product slug already exists (case-insensitive).
     * Excludes soft-deleted products.
     *
     * @param string $slug Slug to check
     * @param int|null $excludeId Product ID to exclude (for edit operations)
     * @return bool True if slug exists
     */
    public function productSlugExists($slug, $excludeId = null)
    {
        if (empty($slug) || !is_string($slug)) {
            return false;
        }
        $slug = strtolower(trim($slug));
        $escapedSlug = $this->db->escape($slug);
        
        $sql = "SELECT 1 FROM products WHERE LOWER(TRIM(slug)) = LOWER(TRIM({$escapedSlug})) AND slug IS NOT NULL AND status <> 'DELETED'";
        if ($excludeId !== null) {
            $sql .= " AND id <> " . (int)$excludeId;
        }
        $sql .= " LIMIT 1";
        
        $result = $this->db->query($sql)->getResultArray();
        return !empty($result);
    }
    
    /**
     * Insert product into database
     * 
     * CRITICAL: Enhanced error handling to catch silent failures
     * 
     * @param array $dataArr Product data array
     * @return int|false Product ID on success, false on failure
     */
    public function insertProduct($dataArr)
    {
        try {
            $filteredData = [];
            foreach ($this->allowedFields as $field) {
                if (isset($dataArr[$field])) {
                    $filteredData[$field] = $dataArr[$field];
                }
            }

            $result = $this->db->table('products')->insert($filteredData);

            if (!$result) {
                $dbError = $this->db->error();
                log_message('error', 'ProductModel::insertProduct - failed: ' . ($dbError['message'] ?? 'unknown'));
                return false;
            }

            $insertId = $this->db->insertID();
            return $insertId > 0 ? $insertId : false;
        } catch (\Exception $e) {
            log_message('error', 'ProductModel::insertProduct - Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    public function insertProductPrice($dataArr)
    {
        // DEPRECATED: Price is now stored directly in products table
        if (isset($dataArr['product_id'])) {
            $productId = $dataArr['product_id'];
            $updateData = [];
            
            if (isset($dataArr['mrp'])) $updateData['mrp'] = $dataArr['mrp'];
            if (isset($dataArr['discount'])) $updateData['discount'] = $dataArr['discount'];
            if (isset($dataArr['discount_off_inpercent'])) $updateData['discount_off_inpercent'] = $dataArr['discount_off_inpercent'];
            if (isset($dataArr['sale_price'])) $updateData['sale_price'] = $dataArr['sale_price'];
            if (isset($dataArr['final_price'])) $updateData['final_price'] = $dataArr['final_price'];
            if (isset($dataArr['unit_id'])) $updateData['unit_id'] = $dataArr['unit_id'];
            if (isset($dataArr['unit_name'])) $updateData['unit_name'] = $dataArr['unit_name'];
            
            $builder = $this->db->table('products');
            $builder->where('id', $productId);
            
            if ($builder->update($updateData)) {
                return $productId;
            }
        }
        
        return false;
    }
    
    public function insertProductImage($dataArr)
    {
        $result = $this->db->table('product_image')->insert($dataArr);
        
        if ($result) {
            return $this->db->insertID();
        }
        
        return false;
    }
    
    public function getProductDetails($pid)
    {
        $builder = $this->db->table('products as P');
        $builder->select('P.*, PC.category as category_name, PB.brand as brand_name');
        $builder->join('product_category AS PC', 'P.category_id = PC.id AND PC.status <> \'DELETED\'', 'left');
        $builder->join('product_brand AS PB', 'P.brand_id = PB.id AND P.brand_id > 0 AND PB.status <> \'DELETED\'', 'left');
        $builder->where('P.id', $pid);
        $builder->where('P.status <>', 'DELETED');
        $builder->orderBy('P.id', 'DESC');
        
        $result = $builder->get()->getResultArray();
        return !empty($result) ? $result[0] : null;
    }
    
    public function getProductImageDetails($pid)
    {
        return $this->getProductImageDetailsOrdered($pid);
    }
    
    public function updateProduct($pid, $dataArr)
    {
        return $this->update($pid, $dataArr);
    }
    
    public function updateProductImage($pid, $dataArr)
    {
        $builder = $this->db->table('product_image');
        $builder->where('product_id', $pid);
        
        return $builder->update($dataArr);
    }
    
    public function getUnitList($status)
    {
        $builder = $this->db->table('units');
        $builder->where('status', $status);
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getUnitDetails($status, $unitId)
    {
        $builder = $this->db->table('units');
        $builder->where('status', $status);
        $builder->where('id', $unitId);
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getPrices($status, $productId)
    {
        // DEPRECATED: Price is now stored directly in products table
        $builder = $this->db->table('products');
        $builder->select('id, id as product_id, 1 as qty, unit_id, unit_name, mrp, discount as discount_inr, discount_off_inpercent as discount_percent, sale_price, final_price, status, created_on, created_id');
        $builder->where('status', $status);
        $builder->where('id', $productId);
        
        return $builder->get()->getResultArray();
    }
    
    public function getproductPriceDetails($pid)
    {
        // DEPRECATED: Price is now stored directly in products table
        $builder = $this->db->table('products');
        $builder->select('id, id as product_id, 1 as qty, unit_id, unit_name, mrp, discount, discount_off_inpercent, sale_price, final_price, status, created_on, created_id');
        $builder->where('id', $pid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function updateProductPrice($pid, $dataArr)
    {
        // DEPRECATED: Price is now stored directly in products table
        $updateData = [];
        
        if (isset($dataArr['mrp'])) {
            $updateData['mrp'] = $dataArr['mrp'];
            $updateData['product_price'] = $dataArr['mrp']; // Legacy field
        }
        if (isset($dataArr['discount'])) {
            $updateData['discount'] = $dataArr['discount'];
            $updateData['discount_price'] = $dataArr['discount']; // Legacy field
        }
        if (isset($dataArr['discount_off_inpercent'])) $updateData['discount_off_inpercent'] = $dataArr['discount_off_inpercent'];
        if (isset($dataArr['sale_price'])) $updateData['sale_price'] = $dataArr['sale_price'];
        if (isset($dataArr['final_price'])) $updateData['final_price'] = $dataArr['final_price'];
        if (isset($dataArr['unit_id'])) $updateData['unit_id'] = $dataArr['unit_id'];
        if (isset($dataArr['unit_name'])) $updateData['unit_name'] = $dataArr['unit_name'];
        
        // Recalculate sale_price if mrp and discount are provided
        if (isset($dataArr['mrp'])) {
            $mrp = floatval($dataArr['mrp']);
            $discount = isset($dataArr['discount']) ? floatval($dataArr['discount']) : 0;
            $discountPercent = isset($dataArr['discount_off_inpercent']) ? floatval($dataArr['discount_off_inpercent']) : 0;
            
            if ($discount > 0) {
                $updateData['sale_price'] = $mrp - $discount;
            } elseif ($discountPercent > 0) {
                $updateData['sale_price'] = $mrp - ($mrp * $discountPercent / 100);
            } else {
                $updateData['sale_price'] = $mrp;
            }
            
            $gstRate = isset($dataArr['gst_rate']) ? floatval($dataArr['gst_rate']) : 0;
            if ($gstRate > 0) {
                $updateData['final_price'] = $updateData['sale_price'] + ($updateData['sale_price'] * $gstRate / 100);
            } else {
                $updateData['final_price'] = $updateData['sale_price'];
            }
        }
        
        return $this->update($pid, $updateData);
    }
    
    // Product Variations Methods
    public function getProductVariations($productId, $includeDeleted = false)
    {
        $builder = $this->db->table('product_variations');
        $builder->where('product_id', (int)$productId);
        
        if (!$includeDeleted) {
            $builder->where('is_deleted', 0);
        }
        
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductVariationDetails($variationId)
    {
        $builder = $this->db->table('product_variations');
        $builder->where('id', (int)$variationId);
        $builder->where('is_deleted', 0);
        $builder->orderBy('id', 'DESC');
        $builder->limit(1);
        
        $result = $builder->get()->getResultArray();
        return !empty($result) ? $result[0] : null;
    }

    public function getVariationImagesForProduct($productId)
    {
        $builder = $this->db->table('product_variation_images AS PVI');
        $builder->select('PVI.id, PVI.variation_id, PVI.image, PVI.alt_text, PVI.display_order');
        $builder->join('product_variations AS PV', 'PVI.variation_id = PV.id', 'inner');
        $builder->where('PV.product_id', (int)$productId);
        $builder->where('PVI.status !=', 'DELETED');
        $builder->orderBy('PVI.variation_id', 'ASC');
        $builder->orderBy('PVI.display_order', 'ASC');
        $builder->orderBy('PVI.id', 'ASC');
        return $builder->get()->getResultArray();
    }

    /**
     * Get images for a specific variation
     */
    public function getVariationImages($variationId)
    {
        $builder = $this->db->table('product_variation_images');
        $builder->where('variation_id', (int)$variationId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $images = $builder->get()->getResultArray();
        
        // Convert image paths to full URLs
        foreach ($images as &$image) {
            if (isset($image['image']) && !empty($image['image'])) {
                if (!preg_match('/^https?:\/\//', $image['image'])) {
                    $image['image'] = $this->getAssetUrl('assets/productimages/' . $image['image']);
                }
            }
        }
        
        return $images;
    }

    /**
     * Get single variation image details
     */
    public function getVariationImageDetails($imageId)
    {
        $builder = $this->db->table('product_variation_images');
        $builder->where('id', (int)$imageId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }

    /**
     * Insert variation image
     */
    public function insertVariationImage($dataArr)
    {
        $sanitized = [
            'variation_id' => (int)$dataArr['variation_id'],
            'image' => $dataArr['image'],
            'alt_text' => $dataArr['alt_text'] ?? null,
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => $dataArr['status'] ?? 'ACTIVE'
        ];
        
        $result = $this->db->table('product_variation_images')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }

    /**
     * Delete variation image (soft delete)
     */
    public function deleteVariationImage($imageId)
    {
        $builder = $this->db->table('product_variation_images');
        $builder->where('id', (int)$imageId);
        
        return $builder->update(['status' => 'DELETED']);
    }

    /**
     * Get next display order for variation images
     */
    public function getNextVariationImageDisplayOrder($variationId)
    {
        $builder = $this->db->table('product_variation_images');
        $builder->selectMax('display_order', 'max_order');
        $builder->where('variation_id', (int)$variationId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return (int)($result['max_order'] ?? 0) + 1;
    }

    public function getProductVariationsStructured($productId, $productGstRate = 0)
    {
        $variations = $this->getProductVariations((int)$productId);
        $imagesByVar = [];
        try {
            $varImgs = $this->getVariationImagesForProduct((int)$productId);
            foreach ($varImgs as $vi) {
                $vid = (int)$vi['variation_id'];
                if (!isset($imagesByVar[$vid])) {
                    $imagesByVar[$vid] = [];
                }
                // Convert image path to full URL
                $imageUrl = $vi['image'] ?? '';
                if (!empty($imageUrl) && !preg_match('/^https?:\/\//', $imageUrl)) {
                    $imageUrl = $this->getAssetUrl('assets/productimages/' . $imageUrl);
                }
                
                $imagesByVar[$vid][] = [
                    'id' => (int)$vi['id'],
                    'image' => $imageUrl,
                    'alt_text' => $vi['alt_text'] ?? null,
                    'display_order' => isset($vi['display_order']) ? (int)$vi['display_order'] : 0
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'getProductVariationsStructured images: ' . $e->getMessage());
        }

        $typeLabels = ['color' => 'Color', 'size' => 'Size', 'material' => 'Material', 'variant' => 'Variant'];
        $grouped = [];

        foreach ($variations as $v) {
            $typeKey = $v['variation_type'] ?? null;
            if (empty($typeKey)) {
                $typeKey = !empty($v['color_name']) ? 'color' : (!empty($v['size']) ? 'size' : 'variant');
            }
            $optionValue = $v['option_value'] ?? null;
            if (empty($optionValue)) {
                $optionValue = !empty($v['color_name']) ? $v['color_name'] : (!empty($v['size']) ? $v['size'] : ($v['variation_name'] ?? ''));
            }

            $price = isset($v['price']) ? (float)$v['price'] : 0;
            $discountPercent = isset($v['discount_percent']) ? (float)$v['discount_percent'] : 0;
            $discountValue = isset($v['discount_value']) ? (float)$v['discount_value'] : 0;
            $salePrice = $price;
            if ($discountValue > 0) {
                $salePrice = $price - $discountValue;
            } elseif ($discountPercent > 0) {
                $salePrice = $price - ($price * $discountPercent / 100);
            }

            $gstRate = isset($v['gst_rate']) ? (float)$v['gst_rate'] : (float)$productGstRate;
            $finalPrice = $salePrice;
            if ($gstRate > 0) {
                $finalPrice = $salePrice + ($salePrice * $gstRate / 100);
            }

            $option = [
                'id' => (int)$v['id'],
                'option_value' => $optionValue,
                'sku' => $v['sku'] ?? '',
                'price' => $price,
                'sale_price' => $salePrice,
                'final_price' => $finalPrice,
                'stock_quantity' => (int)($v['stock_quantity'] ?? 0),
                'in_stock' => (int)($v['stock_quantity'] ?? 0) > 0 ? 'YES' : 'NO',
                'images' => $imagesByVar[(int)$v['id']] ?? [],
                'option_meta' => array_filter([
                    'color_code' => $v['color_code'] ?? null,
                    'color_name' => $v['color_name'] ?? null,
                    'size' => $v['size'] ?? null
                ])
            ];

            if (!isset($grouped[$typeKey])) {
                $grouped[$typeKey] = [
                    'variation_type' => $typeLabels[$typeKey] ?? ucfirst($typeKey),
                    'type_key' => $typeKey,
                    'options' => []
                ];
            }
            $grouped[$typeKey]['options'][] = $option;
        }

        return array_values($grouped);
    }
    
    public function variationSkuExists($sku, $excludeId = null)
    {
        $builder = $this->db->table('product_variations');
        $builder->where('sku', $sku);
        $builder->where('is_deleted', 0);
        
        if ($excludeId !== null) {
            $builder->where('id <>', (int)$excludeId);
        }
        
        $builder->orderBy('id', 'DESC');
        
        return $builder->get()->getResultArray();
    }
    
    public function productExists($productId)
    {
        $builder = $this->db->table('products');
        $builder->where('id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->limit(1);
        
        $result = $builder->get()->getResultArray();
        return !empty($result);
    }
    
    public function insertProductVariation($dataArr)
    {
        $sanitizedData = [];
        
        if (isset($dataArr['product_id'])) {
            $sanitizedData['product_id'] = (int)$dataArr['product_id'];
        }
        if (isset($dataArr['sku'])) {
            $sanitizedData['sku'] = $dataArr['sku'];
        }
        if (isset($dataArr['variation_name'])) {
            $sanitizedData['variation_name'] = $dataArr['variation_name'];
        }
        // API-aligned: color, size, attributes (per product_variations schema)
        if (array_key_exists('color_name', $dataArr)) {
            $sanitizedData['color_name'] = $dataArr['color_name'] === null || $dataArr['color_name'] === '' ? null : (string)$dataArr['color_name'];
        }
        if (array_key_exists('color_code', $dataArr)) {
            $sanitizedData['color_code'] = $dataArr['color_code'] === null || $dataArr['color_code'] === '' ? null : (string)$dataArr['color_code'];
        }
        if (array_key_exists('size', $dataArr)) {
            $sanitizedData['size'] = $dataArr['size'] === null || $dataArr['size'] === '' ? null : (string)$dataArr['size'];
        }
        
        $sanitizedData['price'] = isset($dataArr['price']) ? floatval($dataArr['price']) : 0.00;
        $sanitizedData['discount_percent'] = isset($dataArr['discount_percent']) ? floatval($dataArr['discount_percent']) : 0.00;
        $sanitizedData['discount_value'] = isset($dataArr['discount_value']) ? floatval($dataArr['discount_value']) : 0.00;

        // Calculate final_price using parent product's GST logic if not provided
        if (isset($dataArr['final_price'])) {
            $sanitizedData['final_price'] = floatval($dataArr['final_price']);
        } else {
            // Load pricing helper if not already loaded
            if (!function_exists('calculate_all_prices')) {
                helper('pricing');
            }
            
            $gst_rate = 0;
            if (isset($sanitizedData['product_id'])) {
                $product = $this->db->table('products')->select('gst_rate')->where('id', $sanitizedData['product_id'])->get()->getRowArray();
                $gst_rate = (float)($product['gst_rate'] ?? 0);
            }
            
            $pricing = calculate_all_prices(
                $sanitizedData['price'], 
                $sanitizedData['discount_value'], 
                $sanitizedData['discount_percent'], 
                $gst_rate
            );
            $sanitizedData['final_price'] = $pricing['final_price'];
        }
        $sanitizedData['stock_quantity'] = isset($dataArr['stock_quantity']) ? (int)$dataArr['stock_quantity'] : 0;
        $sanitizedData['status'] = isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE';
        $sanitizedData['is_deleted'] = isset($dataArr['is_deleted']) ? (int)$dataArr['is_deleted'] : 0;
        
        $result = $this->db->table('product_variations')->insert($sanitizedData);
        
        if ($result) {
            return $this->db->insertID();
        }
        
        return false;
    }
    
    public function updateProductVariation($variationId, $dataArr)
    {
        $variationId = (int)$variationId;
        $allowed = [
            'sku', 'variation_name', 'color_name', 'color_code', 'size',
            'price', 'final_price', 'discount_percent', 'discount_value', 'stock_quantity', 'status', 'is_deleted'
        ];
        $updateData = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $dataArr)) {
                continue;
            }
            if ($key === 'price' || $key === 'final_price' || $key === 'discount_percent' || $key === 'discount_value') {
                $updateData[$key] = round(floatval($dataArr[$key]), 2);
            } elseif ($key === 'stock_quantity' || $key === 'is_deleted') {
                $updateData[$key] = (int)$dataArr[$key];
            } else {
                $updateData[$key] = $dataArr[$key];
            }
        }
        if (empty($updateData)) {
            return true;
        }

        // If pricing fields were changed but final_price wasn't provided, recalculate it
        if (!isset($updateData['final_price']) && (isset($updateData['price']) || isset($updateData['discount_percent']) || isset($updateData['discount_value']))) {
            if (!function_exists('calculate_all_prices')) {
                helper('pricing');
            }

            $current = $this->db->table('product_variations')->where('id', $variationId)->get()->getRowArray();
            if ($current) {
                $mrp = (float)($updateData['price'] ?? $current['price'] ?? 0);
                $dPerc = (float)($updateData['discount_percent'] ?? $current['discount_percent'] ?? 0);
                $dVal = (float)($updateData['discount_value'] ?? $current['discount_value'] ?? 0);
                $gstRate = 0;
                $pId = (int)($current['product_id'] ?? 0);
                if ($pId > 0) {
                    $pRow = $this->db->table('products')->select('gst_rate')->where('id', $pId)->get()->getRowArray();
                    $gstRate = (float)($pRow['gst_rate'] ?? 0);
                }
                $pricing = calculate_all_prices($mrp, $dVal, $dPerc, $gstRate);
                $updateData['final_price'] = $pricing['final_price'];
            }
        }

        $builder = $this->db->table('product_variations');
        $builder->where('id', $variationId);
        return $builder->update($updateData);
    }

    /**
     * Update stock by SKU (for EasyEcom inventory webhook).
     * Tries product_variations.sku first, then products.sku_number.
     *
     * @param string $sku      SKU (EasyEcom or internal)
     * @param int    $quantity New stock quantity (>= 0)
     * @return array ['updated' => bool, 'type' => 'variation'|'product'|null, 'message' => string]
     */
    public function updateStockBySku(string $sku, int $quantity): array
    {
        $quantity = max(0, (int) $quantity);
        helper('stock');
        $stockData = prepare_stock_data($quantity);

        // 1. Try variation by sku
        $builder = $this->db->table('product_variations');
        $builder->where('sku', $sku);
        $builder->where('is_deleted', 0);
        $row = $builder->get()->getRowArray();
        if (!empty($row)) {
            $builder = $this->db->table('product_variations');
            $builder->where('id', (int) $row['id']);
            $ok = $builder->update(['stock_quantity' => $stockData['stock_quantity']]);
            return [
                'updated' => (bool) $ok,
                'type'    => 'variation',
                'message' => $ok ? 'Variation stock updated' : 'Variation update failed',
            ];
        }

        // 2. Try product by sku_number
        $builder = $this->db->table('products');
        $builder->where('sku_number', $sku);
        $builder->where('status !=', 'DELETED');
        $row = $builder->get()->getRowArray();
        if (!empty($row)) {
            $builder = $this->db->table('products');
            $builder->where('id', (int) $row['id']);
            $ok = $builder->update([
                'stock_quantity' => $stockData['stock_quantity'],
                'in_stock'        => $stockData['in_stock'],
            ]);
            return [
                'updated' => (bool) $ok,
                'type'    => 'product',
                'message' => $ok ? 'Product stock updated' : 'Product update failed',
            ];
        }

        return [
            'updated' => false,
            'type'    => null,
            'message' => 'SKU not found: ' . $sku,
        ];
    }

    public function softDeleteProductVariation($variationId)
    {
        $variationId = (int)$variationId;
        $dataArr = ['is_deleted' => 1];
        
        $builder = $this->db->table('product_variations');
        $builder->where('id', $variationId);
        
        return $builder->update($dataArr);
    }
    
    public function getProductCounts()
    {
        $stats = [];
        
        // Total products
        $builder = $this->db->table('products');
        $builder->where('status <>', 'DELETED');
        $stats['total_products'] = $builder->countAllResults(false);
        
        // Active products
        $builder = $this->db->table('products');
        $builder->where('status', 'ACTIVE');
        $stats['active_products'] = $builder->countAllResults(false);
        
        // Inactive products
        $builder = $this->db->table('products');
        $builder->where('status', 'INACTIVE');
        $stats['inactive_products'] = $builder->countAllResults(false);
        
        // Deleted products
        $builder = $this->db->table('products');
        $builder->where('status', 'DELETED');
        $stats['deleted_products'] = $builder->countAllResults(false);
        
        // Products with category
        $builder = $this->db->table('products');
        $builder->where('category_id IS NOT NULL');
        $builder->where('status <>', 'DELETED');
        $stats['products_with_category'] = $builder->countAllResults(false);
        
        // Products without category
        $builder = $this->db->table('products');
        $builder->where('category_id IS NULL');
        $builder->where('status <>', 'DELETED');
        $stats['products_without_category'] = $builder->countAllResults(false);
        
        // Products in stock
        $builder = $this->db->table('products');
        $builder->where('in_stock', 'YES');
        $builder->where('status <>', 'DELETED');
        $stats['products_in_stock'] = $builder->countAllResults(false);
        
        // Products out of stock
        $builder = $this->db->table('products');
        $builder->where('in_stock', 'NO');
        $builder->where('status <>', 'DELETED');
        $stats['products_out_of_stock'] = $builder->countAllResults(false);
        
        // Count by category: main categories by category_id, subcategories by subcategory_id
        $builder = $this->db->table('product_category PC');
        $builder->select('PC.id as category_id, PC.category as category_name, COUNT(P.id) as product_count');
        $builder->join('products P', 'P.category_id = PC.id AND P.status <> \'DELETED\'', 'left');
        $builder->where('PC.status <>', 'DELETED');
        $builder->groupStart();
        $builder->where('PC.parent_id IS NULL');
        $builder->orWhere('PC.parent_id', 0);
        $builder->groupEnd();
        $builder->groupBy('PC.id, PC.category');
        $mainCounts = $builder->get()->getResultArray();
        $builder = $this->db->table('product_category PC');
        $builder->select('PC.id as category_id, PC.category as category_name, COUNT(P.id) as product_count');
        $builder->join('products P', 'P.subcategory_id = PC.id AND P.status <> \'DELETED\'', 'left');
        $builder->where('PC.status <>', 'DELETED');
        $builder->where('PC.parent_id IS NOT NULL');
        $builder->where('PC.parent_id <>', 0);
        $builder->groupBy('PC.id, PC.category');
        $subCounts = $builder->get()->getResultArray();
        $byCategory = array_merge($mainCounts, $subCounts);
        foreach ($byCategory as &$row) {
            $row['product_count'] = (int) $row['product_count'];
        }
        usort($byCategory, function ($a, $b) {
            if ($a['product_count'] !== $b['product_count']) {
                return $b['product_count'] - $a['product_count'];
            }
            return strcmp($a['category_name'], $b['category_name']);
        });
        $stats['by_category'] = $byCategory;
        
        // Count by brand
        $builder = $this->db->table('product_brand PB');
        $builder->select('PB.id as brand_id, PB.brand as brand_name, COUNT(P.id) as product_count');
        $builder->join('products P', 'P.brand_id = PB.id AND P.status <> \'DELETED\'', 'left');
        $builder->where('PB.status <>', 'DELETED');
        $builder->groupBy('PB.id, PB.brand');
        $builder->orderBy('product_count', 'DESC');
        $builder->orderBy('PB.brand', 'ASC');
        $stats['by_brand'] = $builder->get()->getResultArray();
        
        return $stats;
    }
    
    public function getCachedProductStatistics()
    {
        $builder = $this->db->table('product_statistics');
        $builder->where('id', 1);
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function getProductCategoryListWithHierarchy($status = null, $includeDeleted = false)
    {
        $builder = $this->db->table('product_category');
        
        if ($status === null) {
            if (!$includeDeleted) {
                $builder->where('status <>', 'DELETED');
            }
        } else {
            $builder->where('status', $status);
        }
        
        $builder->orderBy('parent_id', 'ASC');
        $builder->orderBy('sequences', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getCategoryTree($status = 'ACTIVE')
    {
        $categories = $this->getProductCategoryListWithHierarchy($status);
        $tree = [];
        $indexed = [];
        
        foreach ($categories as $cat) {
            $indexed[$cat['id']] = $cat;
            $indexed[$cat['id']]['children'] = [];
        }
        
        foreach ($indexed as $id => $cat) {
            if ($cat['parent_id'] === null || $cat['parent_id'] == 0) {
                $tree[] = &$indexed[$id];
            } else {
                if (isset($indexed[$cat['parent_id']])) {
                    $indexed[$cat['parent_id']]['children'][] = &$indexed[$id];
                }
            }
        }
        
        return $tree;
    }
    
    public function isCategoryLeaf($categoryId)
    {
        $builder = $this->db->table('product_category');
        $builder->where('parent_id', (int)$categoryId);
        $builder->where('status <>', 'DELETED');
        
        return $builder->countAllResults() == 0;
    }
    
    /**
     * Returns true if $potentialParentId is a descendant of $categoryId (i.e. in the subtree under $categoryId).
     * If so, setting $categoryId's parent to $potentialParentId would create a circular reference.
     * Check: walk from potentialParentId upward; if we ever reach categoryId, then potentialParentId is under categoryId.
     */
    public function isCategoryDescendant($potentialParentId, $categoryId)
    {
        $ancestorId = (int)$categoryId;
        $childId = (int)$potentialParentId;
        if ($childId <= 0 || $ancestorId <= 0) {
            return false;
        }
        $maxDepth = 100;
        $depth = 0;

        while ($childId > 0 && $depth < $maxDepth) {
            $builder = $this->db->table('product_category');
            $builder->select('parent_id');
            $builder->where('id', $childId);
            $builder->where('status <>', 'DELETED');

            $result = $builder->get()->getRowArray();

            if (empty($result)) {
                return false;
            }

            $parentId = isset($result['parent_id']) ? (int)$result['parent_id'] : 0;

            if ($parentId == $ancestorId) {
                return true; // potentialParentId is under categoryId -> would create cycle
            }

            $childId = $parentId;
            $depth++;
        }

        return false;
    }
    
    public function getLeafCategories($status = 'ACTIVE')
    {
        $builder = $this->db->table('product_category PC');
        $builder->select('PC.*');
        $builder->join('product_category PC2', 'PC2.parent_id = PC.id AND PC2.status <> \'DELETED\'', 'left');
        $builder->where('PC.status', $status);
        $builder->where('PC2.id IS NULL', null, false);
        $builder->orderBy('PC.sequences', 'ASC');
        $builder->orderBy('PC.id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function validateCategoryForProduct($categoryId)
    {
        if ($categoryId === null || $categoryId == 0) {
            return true;
        }
        return $this->isCategoryLeaf($categoryId);
    }
    
    public function getCategoryPath($categoryId)
    {
        $path = [];
        $currentId = (int)$categoryId;
        
        while ($currentId > 0) {
            $builder = $this->db->table('product_category');
            $builder->where('id', $currentId);
            $builder->where('status <>', 'DELETED');
            
            $result = $builder->get()->getRowArray();
            
            if (empty($result)) {
                break;
            }
            
            array_unshift($path, $result);
            $currentId = isset($result['parent_id']) ? (int)$result['parent_id'] : 0;
        }
        
        return $path;
    }
    
    // Product Descriptions Methods
    public function getProductDescriptions($productId, $descriptionType = null, $languageCode = 'en')
    {
        $builder = $this->db->table('product_descriptions');
        $builder->where('product_id', (int)$productId);
        $builder->where('language_code', $languageCode);
        $builder->where('status <>', 'DELETED');
        
        if ($descriptionType !== null) {
            $builder->where('description_type', $descriptionType);
        }
        
        $builder->orderBy('description_type', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getAllProductDescriptions($productId)
    {
        $builder = $this->db->table('product_descriptions');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('description_type', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function upsertProductDescription($productId, $descriptionType, $content, $languageCode = 'en', $createdId = null)
    {
        $builder = $this->db->table('product_descriptions');
        $builder->where('product_id', (int)$productId);
        $builder->where('description_type', $descriptionType);
        $builder->where('language_code', $languageCode);
        
        $existing = $builder->get()->getRowArray();
        
        $data = [
            'product_id' => (int)$productId,
            'description_type' => $descriptionType,
            'language_code' => $languageCode,
            'content' => $content,
            'status' => 'ACTIVE'
        ];
        
        if ($createdId !== null) {
            $data['created_id'] = (int)$createdId;
        }
        
        if (!empty($existing)) {
            $builder = $this->db->table('product_descriptions');
            $builder->where('id', $existing['id']);
            return $builder->update($data);
        } else {
            $result = $this->db->table('product_descriptions')->insert($data);
            return $result ? $this->db->insertID() : false;
        }
    }
    
    public function deleteProductDescription($descriptionId)
    {
        $builder = $this->db->table('product_descriptions');
        $builder->where('id', (int)$descriptionId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    // Product Image Ordering Methods
    public function getProductImageDetailsOrdered($pid)
    {
        $builder = $this->db->table('product_image');
        $builder->where('product_id', (int)$pid);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $images = $builder->get()->getResultArray();
        
        // Convert image paths to full URLs
        foreach ($images as &$image) {
            if (isset($image['image']) && !empty($image['image'])) {
                // Skip if already a full URL or base64 data URL
                if (!preg_match('/^https?:\/\//', $image['image']) && !preg_match('/^data:image\//', $image['image'])) {
                    // It's a relative path, convert to full URL
                    // Image is stored as filename only (e.g., "product_13_1770753985_0.webp")
                    $image['image'] = $this->getAssetUrl('assets/productimages/' . $image['image']);
                }
            }
        }
        
        return $images;
    }
    
    public function updateImageDisplayOrder($imageId, $displayOrder)
    {
        $builder = $this->db->table('product_image');
        $builder->where('id', (int)$imageId);
        
        return $builder->update(['display_order' => (int)$displayOrder]);
    }
    
    public function bulkUpdateImageOrders($imageOrders)
    {
        // Use model's database connection for transaction consistency
        $this->db->transStart();
        
        foreach ($imageOrders as $order) {
            $builder = $this->db->table('product_image');
            $builder->where('id', (int)$order['id']);
            $builder->update(['display_order' => (int)$order['display_order']]);
        }
        
        $this->db->transComplete();
        return $this->db->transStatus();
    }
    
    public function getNextImageDisplayOrder($productId)
    {
        $builder = $this->db->table('product_image');
        $builder->selectMax('display_order', 'max_order');
        $builder->where('product_id', (int)$productId);
        
        $result = $builder->get()->getRowArray();
        return (int)($result['max_order'] ?? 0) + 1;
    }
    
    public function getCurrentMaxImageDisplayOrder($productId)
    {
        $builder = $this->db->table('product_image');
        $builder->selectMax('display_order', 'max_order');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return isset($result['max_order']) ? (int)$result['max_order'] : null;
    }
    
    // Product Description Images Methods
    public function getAllDescriptionImages($productId)
    {
        $builder = $this->db->table('product_description_images');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('description_type', 'ASC');
        $builder->orderBy('language_code', 'ASC');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $images = $builder->get()->getResultArray();
        
        // Convert image paths to full URLs
        foreach ($images as &$image) {
            if (isset($image['image']) && !empty($image['image'])) {
                // Skip if already a full URL or base64 data URL
                if (!preg_match('/^https?:\/\//', $image['image']) && !preg_match('/^data:image\//', $image['image'])) {
                    // It's a relative path, convert to full URL
                    if (strpos($image['image'], 'assets/') === 0) {
                        $image['image'] = $this->getAssetUrl($image['image']);
                    } else {
                        $image['image'] = $this->getAssetUrl('assets/productimages/' . $image['image']);
                    }
                }
            }
        }
        
        return $images;
    }

    public function getDescriptionImages($productId, $descriptionType = null, $languageCode = 'en')
    {
        $builder = $this->db->table('product_description_images');
        $builder->where('product_id', (int)$productId);
        $builder->where('language_code', $languageCode);
        $builder->where('status <>', 'DELETED');
        
        if ($descriptionType !== null) {
            $builder->where('description_type', $descriptionType);
        }
        
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getDescriptionImage($imageId)
    {
        $builder = $this->db->table('product_description_images');
        $builder->where('id', (int)$imageId);
        $builder->where('status <>', 'DELETED');
        
        return $builder->get()->getRowArray();
    }
    
    public function insertDescriptionImage($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'description_type' => $dataArr['description_type'],
            'language_code' => $dataArr['language_code'],
            'image' => $dataArr['image'],
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['alt_text']) && $dataArr['alt_text'] !== null) {
            $sanitized['alt_text'] = $dataArr['alt_text'];
        }
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_description_images')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateDescriptionImage($imageId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['alt_text'])) {
            $sanitized['alt_text'] = $dataArr['alt_text'] !== null ? $dataArr['alt_text'] : null;
        }
        
        if (isset($dataArr['display_order'])) {
            $sanitized['display_order'] = (int)$dataArr['display_order'];
        }
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_description_images');
        $builder->where('id', (int)$imageId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteDescriptionImage($imageId)
    {
        $builder = $this->db->table('product_description_images');
        $builder->where('id', (int)$imageId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function getNextDescriptionImageOrder($productId, $descriptionType, $languageCode)
    {
        $builder = $this->db->table('product_description_images');
        $builder->selectMax('display_order', 'max_order');
        $builder->where('product_id', (int)$productId);
        $builder->where('description_type', $descriptionType);
        $builder->where('language_code', $languageCode);
        
        $result = $builder->get()->getRowArray();
        return (int)($result['max_order'] ?? 0) + 1;
    }
    
    // Bulk Operations
    public function bulkUpdateProducts($productIds, $dataArr)
    {
        if (empty($productIds) || !is_array($productIds)) {
            return ['success' => 0, 'failed' => 0, 'total' => 0];
        }
        
        $validIds = [];
        foreach ($productIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $validIds[] = $id;
            }
        }
        
        if (empty($validIds)) {
            return ['success' => 0, 'failed' => 0, 'total' => 0];
        }
        
        $allowedFields = [
            'status', 'in_stock', 'stock_quantity', 'home_display_status',
            'category_id', 'subcategory_id', 'brand_id', 'gst_rate', 'product_type'
        ];
        
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($dataArr[$field])) {
                $updateData[$field] = $dataArr[$field];
            }
        }
        
        if (empty($updateData)) {
            return ['success' => 0, 'failed' => count($validIds), 'total' => count($validIds)];
        }
        
        // If stock_quantity is being updated, also update in_stock
        if (isset($updateData['stock_quantity'])) {
            helper('stock');
            $stock_data = prepare_stock_data($updateData['stock_quantity']);
            $updateData['stock_quantity'] = $stock_data['stock_quantity'];
            $updateData['in_stock'] = $stock_data['in_stock'];
        }
        
        $builder = $this->db->table('products');
        $builder->whereIn('id', $validIds);
        $result = $builder->update($updateData);
        
        if ($result) {
            $affected = $this->db->affectedRows();
            return [
                'success' => $affected,
                'failed' => count($validIds) - $affected,
                'total' => count($validIds)
            ];
        } else {
            return [
                'success' => 0,
                'failed' => count($validIds),
                'total' => count($validIds)
            ];
        }
    }
    
    public function bulkDeleteProducts($productIds)
    {
        return $this->bulkUpdateProducts($productIds, ['status' => 'DELETED']);
    }
    
    public function bulkRestoreProducts($productIds)
    {
        return $this->bulkUpdateProducts($productIds, ['status' => 'ACTIVE']);
    }
    
    public function bulkUpdateProductStatus($productIds, $status)
    {
        $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
        if (!in_array($status, $allowedStatuses)) {
            return ['success' => 0, 'failed' => count($productIds), 'total' => count($productIds)];
        }
        return $this->bulkUpdateProducts($productIds, ['status' => $status]);
    }
    
    public function validateProductIds($productIds)
    {
        if (empty($productIds) || !is_array($productIds)) {
            return [];
        }
        
        $validIds = [];
        foreach ($productIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $validIds[] = $id;
            }
        }
        
        if (empty($validIds)) {
            return [];
        }
        
        $builder = $this->db->table('products');
        $builder->select('id');
        $builder->whereIn('id', $validIds);
        
        $result = $builder->get()->getResultArray();
        
        $existingIds = [];
        foreach ($result as $row) {
            $existingIds[] = (int)$row['id'];
        }
        
        return $existingIds;
    }
    
    // ============================================================
    // Product Color Variants Methods
    // ============================================================
    
    public function getProductColorVariants($productId)
    {
        $builder = $this->db->table('product_color_variants');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductColorVariantDetails($colorId)
    {
        $builder = $this->db->table('product_color_variants');
        $builder->where('id', (int)$colorId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductColorVariant($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'color_name' => $dataArr['color_name'],
            'color_code' => $dataArr['color_code'] ?? null,
            'slug' => $dataArr['slug'] ?? null,
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_color_variants')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductColorVariant($colorId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['color_name'])) $sanitized['color_name'] = $dataArr['color_name'];
        if (isset($dataArr['color_code'])) $sanitized['color_code'] = $dataArr['color_code'];
        if (isset($dataArr['slug'])) $sanitized['slug'] = $dataArr['slug'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_color_variants');
        $builder->where('id', (int)$colorId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductColorVariant($colorId)
    {
        $builder = $this->db->table('product_color_variants');
        $builder->where('id', (int)$colorId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductColorVariants($productId, $colors)
    {
        // Soft delete existing colors
        $builder = $this->db->table('product_color_variants');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new colors
        if (!empty($colors) && is_array($colors)) {
            $usedSlugs = []; // Track slugs used in this batch to prevent duplicates
            
            foreach ($colors as $index => $color) {
                // Validate that required fields exist
                if (!is_array($color)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductColorVariants - Invalid color format at index ' . $index . ': not an array');
                    continue;
                }
                
                $color_name = $color['color_name'] ?? null;
                if (empty($color_name)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductColorVariants - Skipping color at index ' . $index . ': missing color_name');
                    continue;
                }
                
                // Generate or validate slug
                $slug = $color['slug'] ?? null;
                
                // If slug is not provided or empty, generate from color_name
                if (empty($slug)) {
                    $slug = strtolower(trim($color_name));
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                    $slug = preg_replace('/^-+|-+$/', '', $slug);
                }
                
                // Ensure slug is unique within this batch
                $baseSlug = $slug;
                $counter = 1;
                while (in_array($slug, $usedSlugs)) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                
                // Check if slug already exists in database for this product (including soft-deleted)
                $builder = $this->db->table('product_color_variants');
                $builder->where('product_id', (int)$productId);
                $builder->where('slug', $slug);
                $existing = $builder->get()->getRowArray();
                
                if ($existing) {
                    // Slug exists, make it unique by appending counter
                    $baseSlug = $slug;
                    $counter = 1;
                    do {
                        $slug = $baseSlug . '-' . $counter;
                        $builder = $this->db->table('product_color_variants');
                        $builder->where('product_id', (int)$productId);
                        $builder->where('slug', $slug);
                        $existing = $builder->get()->getRowArray();
                        $counter++;
                    } while ($existing && $counter < 1000); // Safety limit
                }
                
                // Add to used slugs for this batch
                $usedSlugs[] = $slug;
                
                $this->insertProductColorVariant([
                    'product_id' => $productId,
                    'color_name' => $color_name,
                    'color_code' => $color['color_code'] ?? null,
                    'slug' => $slug,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }
    
    // ============================================================
    // Product Specifications Methods
    // ============================================================
    
    public function getProductSpecifications($productId)
    {
        $builder = $this->db->table('product_specifications');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $specifications = $builder->get()->getResultArray();
        
        // Process specifications to convert icon paths to full URLs
        foreach ($specifications as &$spec) {
            if (isset($spec['icon']) && !empty($spec['icon'])) {
                // Skip if already a full URL or base64 data
                if (!preg_match('/^https?:\/\//', $spec['icon']) && !preg_match('/^data:image\//', $spec['icon'])) {
                    // It's a relative path, convert to full URL
                    if (strpos($spec['icon'], 'assets/') === 0) {
                        $spec['icon'] = $this->getAssetUrl($spec['icon']);
                    } else {
                        // Assume it's just a filename, prepend the path
                        $spec['icon'] = $this->getAssetUrl('assets/productimages/' . $spec['icon']);
                    }
                }
            }
        }
        
        return $specifications;
    }
    
    public function getProductSpecificationDetails($specId)
    {
        $builder = $this->db->table('product_specifications');
        $builder->where('id', (int)$specId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductSpecification($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'icon' => $dataArr['icon'] ?? null,
            'specification_text' => $dataArr['specification_text'],
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_specifications')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductSpecification($specId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['icon'])) $sanitized['icon'] = $dataArr['icon'];
        if (isset($dataArr['specification_text'])) $sanitized['specification_text'] = $dataArr['specification_text'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_specifications');
        $builder->where('id', (int)$specId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductSpecification($specId)
    {
        $builder = $this->db->table('product_specifications');
        $builder->where('id', (int)$specId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductSpecifications($productId, $specifications)
    {
        // Soft delete existing specifications
        $builder = $this->db->table('product_specifications');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new specifications
        if (!empty($specifications) && is_array($specifications)) {
            foreach ($specifications as $index => $spec) {
                // Validate that required fields exist
                if (!is_array($spec)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductSpecifications - Invalid specification format at index ' . $index . ': not an array');
                    continue;
                }
                
                $specification_text = $spec['specification_text'] ?? null;
                if (empty($specification_text)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductSpecifications - Skipping specification at index ' . $index . ': missing specification_text');
                    continue;
                }
                
                $icon = $spec['icon'] ?? null;
                
                // Handle base64 icon images - convert to file
                if (!empty($icon) && preg_match('/^data:image\/(\w+);base64,(.+)$/', $icon, $matches)) {
                    // Convert base64 to file
                    $imageType = $matches[1]; // jpeg, png, webp, etc.
                    $base64Data = $matches[2];
                    
                    // Decode base64
                    $imageData = base64_decode($base64Data);
                    if ($imageData !== false) {
                        // Determine file extension
                        $extensions = [
                            'jpeg' => 'jpg',
                            'jpg' => 'jpg',
                            'png' => 'png',
                            'gif' => 'gif',
                            'webp' => 'webp'
                        ];
                        $file_ext = $extensions[strtolower($imageType)] ?? 'jpg';
                        
                        // Create upload directory if it doesn't exist
                        $upload_dir = FCPATH . 'assets/productimages/';
                        if (!is_dir($upload_dir)) {
                            if (!mkdir($upload_dir, 0755, true)) {
                                log_message('error', 'ProductModel::bulkUpdateProductSpecifications - Failed to create upload directory');
                                $icon = null; // Fallback to null if directory creation fails
                            }
                        }
                        
                        if (is_dir($upload_dir)) {
                            // Generate unique filename
                            $unique_filename = 'spec_icon_' . $productId . '_' . time() . '_' . $index . '.' . $file_ext;
                            $file_path = $upload_dir . $unique_filename;
                            
                            // Save file
                            if (file_put_contents($file_path, $imageData) !== false) {
                                $icon = 'assets/productimages/' . $unique_filename;
                                log_message('info', 'ProductModel::bulkUpdateProductSpecifications - Converted base64 icon to file: ' . $unique_filename);
                            } else {
                                log_message('error', 'ProductModel::bulkUpdateProductSpecifications - Failed to save icon file: ' . $unique_filename);
                                $icon = null;
                            }
                        }
                    } else {
                        log_message('error', 'ProductModel::bulkUpdateProductSpecifications - Failed to decode base64 icon at index ' . $index);
                        $icon = null;
                    }
                }
                
                $this->insertProductSpecification([
                    'product_id' => $productId,
                    'icon' => $icon,
                    'specification_text' => $specification_text,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }

    // ============================================================
    // Product Information Methods (title/description table for Product Detail API)
    // ============================================================

    public function getProductInformation($productId)
    {
        $builder = $this->db->table('product_information');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        return $builder->get()->getResultArray();
    }

    public function bulkUpdateProductInformation($productId, $items)
    {
        $builder = $this->db->table('product_information');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);

        if (!empty($items) && is_array($items)) {
            foreach ($items as $index => $item) {
                if (!is_array($item)) continue;
                $title = $item['title'] ?? '';
                $description = $item['description'] ?? '';
                if ($title === '' && $description === '') continue;
                $builder = $this->db->table('product_information');
                $builder->insert([
                    'product_id' => (int)$productId,
                    'title' => $title,
                    'description' => $description,
                    'display_order' => $index,
                    'status' => 'ACTIVE'
                ]);
            }
        }
        return true;
    }
    
    // ============================================================
    // Product Specification Images Methods
    // ============================================================
    
    public function getProductSpecificationImages($productId)
    {
        $builder = $this->db->table('product_specification_images');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $images = $builder->get()->getResultArray();
        
        // Process images to convert relative paths to full URLs and handle base64 data
        foreach ($images as &$image) {
            if (isset($image['image_url'])) {
                // Check if it's a base64 data URL (should be converted)
                if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $image['image_url'], $matches)) {
                    // This is base64 - convert it to a file
                    $imageType = $matches[1];
                    $base64Data = $matches[2];
                    
                    // Decode base64
                    $imageData = base64_decode($base64Data);
                    if ($imageData !== false) {
                        // Determine file extension
                        $extensions = [
                            'jpeg' => 'jpg',
                            'jpg' => 'jpg',
                            'png' => 'png',
                            'gif' => 'gif',
                            'webp' => 'webp'
                        ];
                        $file_ext = $extensions[strtolower($imageType)] ?? 'jpg';
                        
                        // Create upload directory if it doesn't exist
                        $upload_dir = FCPATH . 'assets/productimages/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Generate unique filename
                        $unique_filename = 'spec_img_' . $productId . '_' . $image['id'] . '_' . time() . '.' . $file_ext;
                        $file_path = $upload_dir . $unique_filename;
                        
                        // Save file
                        if (file_put_contents($file_path, $imageData) !== false) {
                            // Update database with new file path
                            $updateBuilder = $this->db->table('product_specification_images');
                            $updateBuilder->where('id', $image['id']);
                            $updateBuilder->update(['image_url' => 'assets/productimages/' . $unique_filename]);
                            
                            $image['image_url'] = 'assets/productimages/' . $unique_filename;
                            log_message('info', 'ProductModel::getProductSpecificationImages - Converted base64 image to file: ' . $unique_filename);
                        }
                    }
                }
                
                // Convert relative path to full URL if needed
                if (!empty($image['image_url']) && !preg_match('/^https?:\/\//', $image['image_url']) && !preg_match('/^data:image\//', $image['image_url'])) {
                    // It's a relative path, convert to full URL
                    if (strpos($image['image_url'], 'assets/') === 0) {
                        $image['image_url'] = $this->getAssetUrl($image['image_url']);
                    } else {
                        $image['image_url'] = $this->getAssetUrl('assets/productimages/' . $image['image_url']);
                    }
                }
            }
        }
        
        return $images;
    }
    
    public function getProductSpecificationImageDetails($imageId)
    {
        $builder = $this->db->table('product_specification_images');
        $builder->where('id', (int)$imageId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductSpecificationImage($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'image_url' => $dataArr['image_url'],
            'alt_text' => $dataArr['alt_text'] ?? null,
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_specification_images')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductSpecificationImage($imageId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['image_url'])) $sanitized['image_url'] = $dataArr['image_url'];
        if (isset($dataArr['alt_text'])) $sanitized['alt_text'] = $dataArr['alt_text'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_specification_images');
        $builder->where('id', (int)$imageId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductSpecificationImage($imageId)
    {
        $builder = $this->db->table('product_specification_images');
        $builder->where('id', (int)$imageId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductSpecificationImages($productId, $images)
    {
        // Soft delete existing images
        $builder = $this->db->table('product_specification_images');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new images
        if (!empty($images) && is_array($images)) {
            foreach ($images as $index => $image) {
                // Validate that required fields exist
                if (!is_array($image)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductSpecificationImages - Invalid image format at index ' . $index . ': not an array');
                    continue;
                }
                
                $image_url = $image['image_url'] ?? null;
                if (empty($image_url)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductSpecificationImages - Skipping image at index ' . $index . ': missing image_url');
                    continue;
                }
                
                // Check if image_url is a base64 data URL
                if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $image_url, $matches)) {
                    // Convert base64 to file
                    $imageType = $matches[1]; // jpeg, png, webp, etc.
                    $base64Data = $matches[2];
                    
                    // Decode base64
                    $imageData = base64_decode($base64Data);
                    if ($imageData === false) {
                        log_message('error', 'ProductModel::bulkUpdateProductSpecificationImages - Failed to decode base64 image at index ' . $index);
                        continue;
                    }
                    
                    // Determine file extension
                    $extensions = [
                        'jpeg' => 'jpg',
                        'jpg' => 'jpg',
                        'png' => 'png',
                        'gif' => 'gif',
                        'webp' => 'webp'
                    ];
                    $file_ext = $extensions[strtolower($imageType)] ?? 'jpg';
                    
                    // Create upload directory if it doesn't exist
                    $upload_dir = FCPATH . 'assets/productimages/';
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            log_message('error', 'ProductModel::bulkUpdateProductSpecificationImages - Failed to create upload directory');
                            continue;
                        }
                    }
                    
                    // Generate unique filename
                    $unique_filename = 'spec_img_' . $productId . '_' . time() . '_' . $index . '.' . $file_ext;
                    $file_path = $upload_dir . $unique_filename;
                    
                    // Save file
                    if (file_put_contents($file_path, $imageData) === false) {
                        log_message('error', 'ProductModel::bulkUpdateProductSpecificationImages - Failed to save image file: ' . $unique_filename);
                        continue;
                    }
                    
                    // Convert to URL path (relative to base_url)
                    $image_url = 'assets/productimages/' . $unique_filename;
                    
                    log_message('info', 'ProductModel::bulkUpdateProductSpecificationImages - Converted base64 image to file: ' . $unique_filename);
                } else {
                    // Already a URL, use as-is (but ensure it's not a full base64 string)
                    // If it's a very long string that might be base64, check
                    if (strlen($image_url) > 1000 && !preg_match('/^https?:\/\//', $image_url) && !preg_match('/^assets\//', $image_url)) {
                        log_message('warning', 'ProductModel::bulkUpdateProductSpecificationImages - Suspicious long URL at index ' . $index . ', might be base64');
                    }
                }
                
                $this->insertProductSpecificationImage([
                    'product_id' => $productId,
                    'image_url' => $image_url,
                    'alt_text' => $image['alt_text'] ?? null,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }
    
    // ============================================================
    // Product Why Choose Methods
    // ============================================================
    
    public function getProductWhyChoose($productId)
    {
        $builder = $this->db->table('product_why_choose');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductWhyChooseDetails($whyChooseId)
    {
        $builder = $this->db->table('product_why_choose');
        $builder->where('id', (int)$whyChooseId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductWhyChoose($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'icon' => $dataArr['icon'] ?? null,
            'title' => $dataArr['title'],
            'description' => $dataArr['description'] ?? null,
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        if ($this->db->fieldExists('image', 'product_why_choose')) {
            $sanitized['image'] = $dataArr['image'] ?? null;
        }
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }

        $result = $this->db->table('product_why_choose')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductWhyChoose($whyChooseId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['icon'])) $sanitized['icon'] = $dataArr['icon'];
        if (isset($dataArr['title'])) $sanitized['title'] = $dataArr['title'];
        if (isset($dataArr['description'])) $sanitized['description'] = $dataArr['description'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_why_choose');
        $builder->where('id', (int)$whyChooseId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductWhyChoose($whyChooseId)
    {
        $builder = $this->db->table('product_why_choose');
        $builder->where('id', (int)$whyChooseId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductWhyChoose($productId, $whyChoose)
    {
        // Soft delete existing items
        $builder = $this->db->table('product_why_choose');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new items
        if (!empty($whyChoose) && is_array($whyChoose)) {
            foreach ($whyChoose as $index => $item) {
                // Validate that required fields exist
                if (!is_array($item)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductWhyChoose - Invalid item format at index ' . $index . ': not an array');
                    continue;
                }
                
                $title = $item['title'] ?? null;
                if (empty($title)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductWhyChoose - Skipping item at index ' . $index . ': missing title');
                    continue;
                }
                
                // Store uploaded icons in the image field (not icon). Icon field reserved for CSS class etc.
                $iconUpload = $item['icon'] ?? $item['image'] ?? null;
                $imageUpload = $item['image'] ?? null;
                $image = $this->convertBase64ToFileForWhyChoose($iconUpload ?: $imageUpload, $productId, $index, 'img');
                
                $this->insertProductWhyChoose([
                    'product_id' => $productId,
                    'icon' => null,
                    'image' => $image,
                    'title' => $title,
                    'description' => $item['description'] ?? null,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }
    
    /**
     * Convert base64 icon/image to file path for why_choose (same URL format as product images).
     */
    private function convertBase64ToFileForWhyChoose($value, $productId, $index, $type)
    {
        if (empty($value) || !preg_match('/^data:image\/(\w+);base64,(.+)$/', $value, $matches)) {
            return $value;
        }
        $imageType = $matches[1];
        $base64Data = $matches[2];
        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            return null;
        }
        $extensions = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
        $file_ext = $extensions[strtolower($imageType)] ?? 'jpg';
        $upload_dir = FCPATH . 'assets/productimages/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            return null;
        }
        $unique_filename = 'why_choose_' . $type . '_' . $productId . '_' . time() . '_' . $index . '.' . $file_ext;
        $file_path = $upload_dir . $unique_filename;
        if (file_put_contents($file_path, $imageData) !== false) {
            log_message('info', 'ProductModel::bulkUpdateProductWhyChoose - Converted base64 ' . $type . ' to file: ' . $unique_filename);
            return 'assets/productimages/' . $unique_filename;
        }
        return null;
    }
    
    // ============================================================
    // Product Disclaimers Methods
    // ============================================================
    
    public function getProductDisclaimers($productId)
    {
        $builder = $this->db->table('product_disclaimers');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        return $builder->get()->getResultArray();
    }
    
    public function getProductDisclaimerDetails($disclaimerId)
    {
        $builder = $this->db->table('product_disclaimers');
        $builder->where('id', (int)$disclaimerId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductDisclaimer($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'description' => $dataArr['description'],
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_disclaimers')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductDisclaimer($disclaimerId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['description'])) $sanitized['description'] = $dataArr['description'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_disclaimers');
        $builder->where('id', (int)$disclaimerId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductDisclaimer($disclaimerId)
    {
        $builder = $this->db->table('product_disclaimers');
        $builder->where('id', (int)$disclaimerId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductDisclaimers($productId, $disclaimers)
    {
        // Soft delete existing disclaimers
        $builder = $this->db->table('product_disclaimers');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new disclaimers
        if (!empty($disclaimers) && is_array($disclaimers)) {
            foreach ($disclaimers as $index => $disclaimer) {
                // Validate that required fields exist
                if (!is_array($disclaimer)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductDisclaimers - Invalid disclaimer format at index ' . $index . ': not an array');
                    continue;
                }
                
                $description = $disclaimer['description'] ?? null;
                if (empty($description)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductDisclaimers - Skipping disclaimer at index ' . $index . ': missing description');
                    continue;
                }
                
                $this->insertProductDisclaimer([
                    'product_id' => $productId,
                    'description' => $description,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }
    
    // ============================================================
    // Product Brochures Methods
    // ============================================================
    
    public function getProductBrochures($productId)
    {
        $builder = $this->db->table('product_brochures');
        $builder->where('product_id', (int)$productId);
        $builder->where('status <>', 'DELETED');
        $builder->orderBy('display_order', 'ASC');
        $builder->orderBy('id', 'ASC');
        
        $brochures = $builder->get()->getResultArray();
        
        // Convert brochure file paths to full URLs
        foreach ($brochures as &$brochure) {
            if (isset($brochure['file_path']) && !empty($brochure['file_path'])) {
                // Skip if already a full URL
                if (!preg_match('/^https?:\/\//', $brochure['file_path'])) {
                    // It's a relative path, convert to full URL
                    if (strpos($brochure['file_path'], 'assets/') === 0) {
                        $brochure['file_path'] = $this->getAssetUrl($brochure['file_path']);
                    } else {
                        $brochure['file_path'] = $this->getAssetUrl('assets/productimages/' . $brochure['file_path']);
                    }
                }
            }
        }
        
        return $brochures;
    }
    
    public function getProductBrochureDetails($brochureId)
    {
        $builder = $this->db->table('product_brochures');
        $builder->where('id', (int)$brochureId);
        $builder->where('status <>', 'DELETED');
        
        $result = $builder->get()->getRowArray();
        return $result ?: null;
    }
    
    public function insertProductBrochure($dataArr)
    {
        $sanitized = [
            'product_id' => (int)$dataArr['product_id'],
            'thumbnail_url' => $dataArr['thumbnail_url'] ?? null,
            'alt_text' => $dataArr['alt_text'] ?? null,
            'pdf_url' => $dataArr['pdf_url'],
            'file_name' => $dataArr['file_name'] ?? null,
            'file_size' => isset($dataArr['file_size']) ? (int)$dataArr['file_size'] : null,
            'display_order' => isset($dataArr['display_order']) ? (int)$dataArr['display_order'] : 0,
            'status' => isset($dataArr['status']) ? $dataArr['status'] : 'ACTIVE'
        ];
        
        if (isset($dataArr['created_id'])) {
            $sanitized['created_id'] = (int)$dataArr['created_id'];
        }
        
        $result = $this->db->table('product_brochures')->insert($sanitized);
        return $result ? $this->db->insertID() : false;
    }
    
    public function updateProductBrochure($brochureId, $dataArr)
    {
        $sanitized = [];
        
        if (isset($dataArr['thumbnail_url'])) $sanitized['thumbnail_url'] = $dataArr['thumbnail_url'];
        if (isset($dataArr['alt_text'])) $sanitized['alt_text'] = $dataArr['alt_text'];
        if (isset($dataArr['pdf_url'])) $sanitized['pdf_url'] = $dataArr['pdf_url'];
        if (isset($dataArr['file_name'])) $sanitized['file_name'] = $dataArr['file_name'];
        if (isset($dataArr['file_size'])) $sanitized['file_size'] = (int)$dataArr['file_size'];
        if (isset($dataArr['display_order'])) $sanitized['display_order'] = (int)$dataArr['display_order'];
        if (isset($dataArr['status'])) $sanitized['status'] = $dataArr['status'];
        
        if (empty($sanitized)) {
            return false;
        }
        
        $builder = $this->db->table('product_brochures');
        $builder->where('id', (int)$brochureId);
        
        return $builder->update($sanitized);
    }
    
    public function deleteProductBrochure($brochureId)
    {
        $builder = $this->db->table('product_brochures');
        $builder->where('id', (int)$brochureId);
        
        return $builder->update(['status' => 'DELETED']);
    }
    
    public function bulkUpdateProductBrochures($productId, $brochures)
    {
        // Soft delete existing brochures
        $builder = $this->db->table('product_brochures');
        $builder->where('product_id', (int)$productId);
        $builder->update(['status' => 'DELETED']);
        
        // Insert new brochures
        if (!empty($brochures) && is_array($brochures)) {
            foreach ($brochures as $index => $brochure) {
                // Validate that required fields exist
                if (!is_array($brochure)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductBrochures - Invalid brochure format at index ' . $index . ': not an array');
                    continue;
                }
                
                $pdf_url = $brochure['pdf_url'] ?? null;
                if (empty($pdf_url)) {
                    log_message('warning', 'ProductModel::bulkUpdateProductBrochures - Skipping brochure at index ' . $index . ': missing pdf_url');
                    continue;
                }
                
                $this->insertProductBrochure([
                    'product_id' => $productId,
                    'thumbnail_url' => $brochure['thumbnail_url'] ?? null,
                    'alt_text' => $brochure['alt_text'] ?? null,
                    'pdf_url' => $pdf_url,
                    'file_name' => $brochure['file_name'] ?? null,
                    'file_size' => $brochure['file_size'] ?? null,
                    'display_order' => $index
                ]);
            }
        }
        
        return true;
    }
}
