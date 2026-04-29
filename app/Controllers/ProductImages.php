<?php

namespace App\Controllers;

use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ProductImages extends BaseController
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
     * Get product images
     * GET /backend/product-images/{productId}
     */
    public function index()
    {
        // Support both segment 2 and 3 (URI structure can differ by base URL / env)
        $productId = $this->request->getUri()->getSegment(3)
            ?? $this->request->getUri()->getSegment(2);

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

        $images = $this->productModel->getProductImageDetailsOrdered($productId);
        
        return json_success($images);
    }

    /**
     * Update image display order
     * POST /backend/product-images/reorder/{productId}
     */
    public function reorder()
    {
        $productId = $this->request->getUri()->getSegment(3)
            ?? $this->request->getUri()->getSegment(2);

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
        
        $imageOrders = $json['image_orders'] ?? [];

        if (!is_array($imageOrders)) {
            return json_error('image_orders must be an array', 400);
        }

        // Validate and sanitize image orders
        $validOrders = [];
        foreach ($imageOrders as $order) {
            if (!isset($order['id']) || !isset($order['display_order'])) {
                continue;
            }

            $imageId = (int)$order['id'];
            $displayOrder = (int)$order['display_order'];

            if ($imageId > 0 && $displayOrder >= 0) {
                $validOrders[] = [
                    'id' => $imageId,
                    'display_order' => $displayOrder
                ];
            }
        }

        if (empty($validOrders)) {
            return json_error('No valid image orders provided', 400);
        }

        $result = $this->productModel->bulkUpdateImageOrders($validOrders);
        
        if ($result) {
            return json_success(null, 'Image order updated successfully');
        } else {
            return json_error('Failed to update image order', 500);
        }
    }

    /**
     * Update single image display order
     * POST /backend/product-images/order/{imageId}
     */
    public function order()
    {
        $imageId = $this->request->getUri()->getSegment(3);

        if (empty($imageId)) {
            return json_error('Image ID is required', 400);
        }

        $imageId = (int)$imageId;
        if ($imageId <= 0) {
            return json_error('Invalid Image ID', 400);
        }

        // Get JSON input
        $json = $this->request->getJSON(true);
        
        $displayOrder = (int)($json['display_order'] ?? $this->request->getPost('display_order') ?? 0);

        if ($displayOrder < 0) {
            return json_error('Display order must be 0 or greater', 400);
        }

        $result = $this->productModel->updateImageDisplayOrder($imageId, $displayOrder);
        
        if ($result) {
            return json_success(null, 'Image order updated successfully');
        } else {
            return json_error('Failed to update image order', 500);
        }
    }
}
