<?php

namespace App\Controllers;

use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ProductDescriptionImages extends BaseController
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
     * Get description images for a product (GET)
     * Upload description image (POST)
     * GET /backend/product-description-images/{productId}?type=technical&language=en
     * POST /backend/product-description-images/{productId}
     */
    public function index($productId = null)
    {
        // Get product ID from route parameter or URI segment
        if (empty($productId)) {
            $productId = $this->request->getUri()->getSegment(3) 
                        ?? $this->request->getUri()->getSegment(2)
                        ?? null;
        }

        if (empty($productId)) {
            // Debug: Log URI info to help troubleshoot
            $uri = $this->request->getUri();
            log_message('error', 'ProductDescriptionImages: Product ID not found', [
                'method_param' => func_get_args()[0] ?? 'not_provided',
                'uri_path' => $uri->getPath(),
                'uri_segments' => $uri->getSegments(),
                'segment_1' => $uri->getSegment(1),
                'segment_2' => $uri->getSegment(2),
                'segment_3' => $uri->getSegment(3),
                'request_method' => $this->request->getMethod()
            ]);
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

        // Handle POST (upload) - Using CI4 recommended method
        if ($this->request->is('post')) {
            // Get form data
            $descriptionType = $this->request->getPost('description_type');
            $languageCode = $this->request->getPost('language_code') ?: 'en';
            $altText = $this->request->getPost('alt_text');

            // Validate description type
            if (empty($descriptionType)) {
                return json_error('Description type is required', 400);
            }

            $allowedTypes = ['short', 'long', 'technical', 'seo'];
            if (!in_array($descriptionType, $allowedTypes)) {
                return json_error('Invalid description type. Allowed: short, long, technical, seo', 400);
            }

            // Handle file upload - support both single and multiple files
            $files = $this->request->getFiles();
            $imageFiles = [];
            
            // Check for multiple files (image[] format from FormData)
            if (isset($files['image'])) {
                $uploadedFiles = $files['image'];
                // Normalize to array if single file
                if (!is_array($uploadedFiles)) {
                    $uploadedFiles = [$uploadedFiles];
                }
                
                // Collect all valid files
                foreach ($uploadedFiles as $f) {
                    if ($f && $f->isValid() && !$f->hasMoved()) {
                        $imageFiles[] = $f;
                    }
                }
            }
            
            // If no files found, try getFile() method (single file upload)
            if (empty($imageFiles)) {
                $file = $this->request->getFile('image');
                if ($file && $file->isValid() && !$file->hasMoved()) {
                    $imageFiles = [$file];
                }
            }
            
            // Validate that we have at least one valid file
            if (empty($imageFiles)) {
                log_message('error', 'ProductDescriptionImages upload failed: No file received', [
                    'product_id' => $productId,
                    'files_received' => $this->request->getFiles(),
                    'post_data' => $this->request->getPost(),
                    'content_type' => $this->request->getHeaderLine('Content-Type')
                ]);
                return json_error('No valid image files provided', 400);
            }

            // Ensure upload directory exists
            $upload_dir = FCPATH . 'assets/productimages/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    return json_error('Failed to create upload directory', 500);
                }
            }

            $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $uploadedImages = [];
            $errors = [];

            // Get user ID from session
            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $createdId = $checkuservars['userid'] ?? null;

            foreach ($imageFiles as $index => $file) {
                // Validate file size (max 5MB)
                if ($file->getSize() > 5 * 1024 * 1024) {
                    $errors[] = $file->getName() . ': File size exceeds 5MB limit';
                    continue;
                }

                // Validate file type
                $fileMime = $file->getClientMimeType();
                if (!in_array($fileMime, $allowedMimes)) {
                    $errors[] = $file->getName() . ': Invalid file type. Allowed: JPEG, PNG, GIF, WebP';
                    continue;
                }

                // Generate unique filename
                $file_ext = $file->getClientExtension();
                if (empty($file_ext)) {
                    $mime_to_ext = [
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp'
                    ];
                    $file_ext = $mime_to_ext[$fileMime] ?? 'jpg';
                }

                $unique_filename = 'desc_' . $productId . '_' . $descriptionType . '_' . time() . '_' . $index . '_' . uniqid() . '.' . $file_ext;

                // Move uploaded file
                if (!$file->move($upload_dir, $unique_filename)) {
                    $errors[] = $file->getName() . ': Failed to save uploaded file';
                    continue;
                }

                // Get next display order for this product/type/language
                $displayOrder = $this->productModel->getNextDescriptionImageOrder($productId, $descriptionType, $languageCode);

                // Insert image record
                $imageData = [
                    'product_id' => $productId,
                    'description_type' => $descriptionType,
                    'language_code' => $languageCode,
                    'image' => $unique_filename,
                    'alt_text' => $altText ?: null,
                    'display_order' => $displayOrder,
                    'status' => 'ACTIVE',
                    'created_id' => $createdId
                ];

                $imageId = $this->productModel->insertDescriptionImage($imageData);

                if ($imageId) {
                    $uploadedImages[] = [
                        'id' => $imageId,
                        'image' => $unique_filename,
                        'url' => base_url('assets/productimages/' . $unique_filename)
                    ];
                } else {
                    // Delete uploaded file if database insert failed
                    $file_path = $upload_dir . $unique_filename;
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                    $errors[] = $file->getName() . ': Failed to save image record';
                }
            }

            // Return results
            if (!empty($uploadedImages)) {
                $message = count($uploadedImages) . ' image(s) uploaded successfully';
                if (!empty($errors)) {
                    $message .= '. ' . count($errors) . ' file(s) failed: ' . implode(', ', $errors);
                }
                // Return single object if only one image, array if multiple
                if (count($uploadedImages) === 1) {
                    return json_success($uploadedImages[0], $message);
                } else {
                    return json_success($uploadedImages, $message);
                }
            } else {
                return json_error('Failed to upload images. ' . implode(', ', $errors), 400);
            }
        }

        // Handle GET (list images)
        // Get optional filters
        $descriptionType = $this->request->getGet('type'); // short, long, technical, seo
        $languageCode = $this->request->getGet('language') ?: 'en';

        $images = $this->productModel->getDescriptionImages($productId, $descriptionType, $languageCode);
        
        return json_success($images);
    }

    /**
     * Delete description image
     * POST /backend/product-description-images/delete/{imageId}
     */
    public function delete($imageId = null)
    {
        // Get image ID from route parameter or URI segment
        if (empty($imageId)) {
            $imageId = $this->request->getUri()->getSegment(3);
        }

        if (empty($imageId)) {
            return json_error('Image ID is required', 400);
        }

        $imageId = (int)$imageId;
        if ($imageId <= 0) {
            return json_error('Invalid Image ID', 400);
        }

        // Get image info before deletion
        $imageInfo = $this->productModel->getDescriptionImage($imageId);
        
        if (empty($imageInfo)) {
            return json_error('Image not found', 404);
        }

        // Soft delete image record
        $result = $this->productModel->deleteDescriptionImage($imageId);

        if ($result) {
            return json_success(null, 'Image deleted successfully');
        } else {
            return json_error('Failed to delete image', 500);
        }
    }

    /**
     * Update image alt text
     * POST /backend/product-description-images/update/{imageId}
     */
    public function update($imageId = null)
    {
        // Get image ID from route parameter or URI segment
        if (empty($imageId)) {
            $imageId = $this->request->getUri()->getSegment(3);
        }

        if (empty($imageId)) {
            return json_error('Image ID is required', 400);
        }

        $imageId = (int)$imageId;
        if ($imageId <= 0) {
            return json_error('Invalid Image ID', 400);
        }

        // Get JSON input
        $json = $this->request->getJSON(true);
        
        $altText = $json['alt_text'] ?? $this->request->getPost('alt_text');
        $displayOrder = isset($json['display_order']) ? (int)$json['display_order'] : null;

        $updateData = [];
        if ($altText !== null) {
            $updateData['alt_text'] = $altText;
        }
        if ($displayOrder !== null) {
            $updateData['display_order'] = $displayOrder;
        }

        if (empty($updateData)) {
            return json_error('No data to update', 400);
        }

        $result = $this->productModel->updateDescriptionImage($imageId, $updateData);

        if ($result) {
            return json_success(null, 'Image updated successfully');
        } else {
            return json_error('Failed to update image', 500);
        }
    }
}
