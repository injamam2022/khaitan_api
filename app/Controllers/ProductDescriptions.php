<?php

namespace App\Controllers;

use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ProductDescriptions extends BaseController
{
    use ResponseTrait;

    protected $productModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->productModel = new ProductModel();
        helper(['api_helper']);
    }

    /**
     * Get product descriptions
     * GET /backend/product-descriptions/{productId}
     */
    public function index($productId = null)
    {
        // Get product ID from route parameter or URI segment
        if (empty($productId)) {
            $productId = $this->request->getUri()->getSegment(3);
        }

        if (empty($productId)) {
            return json_error('Product ID is required', 400);
        }

        $productId = (int)$productId;
        if ($productId <= 0) {
            return json_error('Invalid Product ID', 400);
        }

        // Verify product exists
        if (!$this->productModel->productExists($productId)) {
            return json_error('Product not found', 404);
        }

        // Get optional filters
        $descriptionType = $this->request->getGet('type'); // short, long, technical, seo
        $languageCode = $this->request->getGet('language') ?: 'en';

        $descriptions = $this->productModel->getProductDescriptions($productId, $descriptionType, $languageCode);
        
        return json_success($descriptions);
    }

    /**
     * Create or update product description
     * POST /backend/product-descriptions/{productId}/save
     */
    public function save($productId = null)
    {
        // Get product ID from route parameter or URI segment
        if (empty($productId)) {
            $productId = $this->request->getUri()->getSegment(3);
        }

        if (empty($productId)) {
            return json_error('Product ID is required', 400);
        }

        $productId = (int)$productId;
        if ($productId <= 0) {
            return json_error('Invalid Product ID', 400);
        }

        // Verify product exists
        if (!$this->productModel->productExists($productId)) {
            return json_error('Product not found', 404);
        }

        // Get JSON input
        $json = $this->request->getJSON(true);
        
        // Use JSON data if available, otherwise fall back to POST
        $descriptionType = $json['description_type'] ?? $this->request->getPost('description_type');
        $content = $json['content'] ?? $this->request->getPost('content');
        $languageCode = $json['language_code'] ?? $this->request->getPost('language_code') ?? 'en';

        // Validate required fields
        if (empty($descriptionType)) {
            return json_error('Description type is required', 400);
        }

        // Validate description type
        $allowedTypes = ['short', 'long', 'technical', 'seo'];
        if (!in_array($descriptionType, $allowedTypes)) {
            return json_error('Invalid description type. Allowed: short, long, technical, seo', 400);
        }

        if ($content === null || $content === '') {
            return json_error('Content is required', 400);
        }

        // Get user ID from session
        $session = \Config\Services::session();
        $checkuservars = $session->get();
        $createdId = $checkuservars['userid'] ?? null;

        // Upsert description
        $result = $this->productModel->upsertProductDescription($productId, $descriptionType, $content, $languageCode, $createdId);
        
        if ($result) {
            return json_success(['id' => $result], 'Product description saved successfully');
        } else {
            return json_error('Failed to save product description', 500);
        }
    }

    /**
     * Delete product description
     * POST /backend/product-descriptions/delete/{descriptionId}
     */
    public function delete($descriptionId = null)
    {
        // Get description ID from route parameter or URI segment
        if (empty($descriptionId)) {
            $descriptionId = $this->request->getUri()->getSegment(3);
        }

        if (empty($descriptionId)) {
            return json_error('Description ID is required', 400);
        }

        $descriptionId = (int)$descriptionId;
        if ($descriptionId <= 0) {
            return json_error('Invalid Description ID', 400);
        }

        $result = $this->productModel->deleteProductDescription($descriptionId);
        
        if ($result) {
            return json_success(null, 'Product description deleted successfully');
        } else {
            return json_error('Failed to delete product description', 500);
        }
    }

    /**
     * Bulk save descriptions
     * POST /backend/product-descriptions/bulk/{productId}
     */
    public function bulk($productId = null)
    {
        // Get product ID from route parameter or URI segment
        if (empty($productId)) {
            $productId = $this->request->getUri()->getSegment(3);
        }

        if (empty($productId)) {
            return json_error('Product ID is required', 400);
        }

        $productId = (int)$productId;
        if ($productId <= 0) {
            return json_error('Invalid Product ID', 400);
        }

        // Verify product exists
        if (!$this->productModel->productExists($productId)) {
            return json_error('Product not found', 404);
        }

        // Get JSON input
        $json = $this->request->getJSON(true);
        
        $descriptions = $json['descriptions'] ?? [];
        $languageCode = $json['language_code'] ?? 'en';

        if (!is_array($descriptions)) {
            return json_error('Descriptions must be an array', 400);
        }

        // Get user ID from session
        $session = \Config\Services::session();
        $checkuservars = $session->get();
        $createdId = $checkuservars['userid'] ?? null;

        $results = [];
        $allowedTypes = ['short', 'long', 'technical', 'seo'];

        foreach ($descriptions as $desc) {
            if (!isset($desc['type']) || !isset($desc['content'])) {
                continue;
            }

            $descriptionType = $desc['type'];
            $content = $desc['content'];

            // Validate description type
            if (!in_array($descriptionType, $allowedTypes)) {
                continue;
            }

            // Upsert description
            $result = $this->productModel->upsertProductDescription($productId, $descriptionType, $content, $languageCode, $createdId);
            
            if ($result) {
                $results[] = [
                    'type' => $descriptionType,
                    'id' => $result,
                    'status' => 'success'
                ];
            } else {
                $results[] = [
                    'type' => $descriptionType,
                    'status' => 'error'
                ];
            }
        }

        return json_success($results, 'Descriptions saved successfully');
    }
}
