<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Debug Product Controller
 * Simple endpoint to debug product creation issues
 */
class DebugProduct extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Debug endpoint - shows exactly what the backend receives
     */
    public function test()
    {
        $this->response->setContentType('application/json');
        
        // Get headers safely
        $headers = $this->request->getHeaders();
        $headersArray = is_array($headers) ? $headers : (method_exists($headers, 'toArray') ? $headers->toArray() : []);
        
        $debug = [
            'method' => $this->request->getMethod(),
            'content_type' => $this->request->getHeaderLine('Content-Type'),
            'has_files' => !empty($this->request->getFiles()),
            'raw_input' => substr($this->request->getBody(), 0, 500), // Limit size
            'post_data' => $this->request->getPost(),
            'json_data' => $this->request->getJSON(true),
            'all_headers' => $headersArray,
        ];
        
        // Try to insert a test product
        try {
            $productModel = new \App\Models\ProductModel();
            
            // Get a valid brand_id
            $brandList = $productModel->getProductBrandList('ACTIVE');
            $brandId = 0;
            if (!empty($brandList) && isset($brandList[0]['id'])) {
                $brandId = (int)$brandList[0]['id'];
            }
            
            $testProduct = [
                'product_code' => 'DEBUG_' . time(),
                'product_name' => 'Debug Test Product',
                'brand_id' => $brandId,
                'mrp' => 100.00,
                'sale_price' => 90.00,
                'final_price' => 90.00,
                'stock_quantity' => 1,
                'in_stock' => 1,
                'status' => 'ACTIVE',
                'product_type' => 'NA',
                'created_id' => 1,
                'created_on' => date('Y-m-d H:i:s')
            ];
            
            $productId = $productModel->insertProduct($testProduct);
            
            $debug['insert_result'] = [
                'product_id' => $productId,
                'success' => $productId > 0,
                'model_errors' => $productModel->errors(),
            ];
            
            if ($productId > 0) {
                $verify = $productModel->getProductDetails($productId);
                $debug['verification'] = [
                    'found' => !empty($verify),
                    'product_data' => $verify
                ];
                
                // Clean up
                $productModel->updateProduct($productId, ['status' => 'DELETED']);
            }
            
        } catch (\Exception $e) {
            $debug['exception'] = [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        return json_success($debug, 'Debug information');
    }
}
