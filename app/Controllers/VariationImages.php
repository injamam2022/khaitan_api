<?php

namespace App\Controllers;

use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class VariationImages extends BaseController
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
     * Get variation images
     * GET /backend/variation-images/{variationId}
     */
    public function index($variationId = null)
    {
        // Get from route parameter first, then fall back to URI segment
        if (empty($variationId)) {
            $variationId = $this->request->getUri()->getSegment(3);
        }

        if (empty($variationId)) {
            return json_error('Variation ID is required', 400);
        }

        $variationId = (int)$variationId;
        if ($variationId <= 0) {
            return json_error('Invalid Variation ID', 400);
        }

        // Verify variation exists
        $variation = $this->productModel->getProductVariationDetails($variationId);
        if (!$variation) {
            return json_error('Variation not found', 404);
        }

        $images = $this->productModel->getVariationImages($variationId);
        
        return json_success($images);
    }

    /**
     * Upload variation image
     * POST /backend/variation-images/{variationId}
     */
    public function upload($variationId = null)
    {
        // Get from route parameter first, then fall back to URI segments
        if (empty($variationId)) {
            // Try different segment positions (CodeIgniter segments can vary based on base URL)
            $variationId = $this->request->getUri()->getSegment(3) 
                        ?? $this->request->getUri()->getSegment(2)
                        ?? null;
        }

        if (empty($variationId)) {
            // Debug: Log URI info to help troubleshoot
            $uri = $this->request->getUri();
            log_message('error', 'VariationImages upload: Variation ID not found', [
                'method_param' => func_get_args()[0] ?? 'not_provided',
                'uri_path' => $uri->getPath(),
                'uri_segments' => $uri->getSegments(),
                'segment_1' => $uri->getSegment(1),
                'segment_2' => $uri->getSegment(2),
                'segment_3' => $uri->getSegment(3),
                'segment_4' => $uri->getSegment(4),
                'route_path' => $uri->getRoutePath(),
                'request_method' => $this->request->getMethod()
            ]);
            return json_error('Variation ID is required', 400);
        }

        $variationId = (int)$variationId;
        if ($variationId <= 0) {
            return json_error('Invalid Variation ID', 400);
        }

        // Verify variation exists
        $variation = $this->productModel->getProductVariationDetails($variationId);
        if (!$variation) {
            return json_error('Variation not found', 404);
        }

        // Handle file upload using CI4's file handling
        // Support both single file and multiple files
        // First try getFile() for single file uploads (recommended method)
        $file = $this->request->getFile('image');
        
        // If single file method doesn't work, try getFiles() for multiple files
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            $files = $this->request->getFiles();
            if (isset($files['image'])) {
                $imageFiles = $files['image'];
                // Normalize to array if single file
                if (!is_array($imageFiles)) {
                    $imageFiles = [$imageFiles];
                }
                
                // Find first valid file
                foreach ($imageFiles as $f) {
                    if ($f && $f->isValid() && !$f->hasMoved()) {
                        $file = $f;
                        break;
                    }
                }
            }
        }
        
        // Validate that we have a valid file
        if (!$file) {
            log_message('error', 'VariationImages upload failed: No file received', [
                'variation_id' => $variationId,
                'files_received' => $this->request->getFiles(),
                'post_data' => $this->request->getPost(),
                'content_type' => $this->request->getHeaderLine('Content-Type')
            ]);
            return json_error('Image file is required', 400);
        }
        
        if ($file->hasMoved()) {
            return json_error('File has already been moved', 400);
        }
        
        if (!$file->isValid()) {
            $error = $file->getErrorString();
            log_message('error', 'VariationImages upload failed: Invalid file', [
                'variation_id' => $variationId,
                'error_code' => $file->getError(),
                'error_message' => $error,
                'file_name' => $file->getName(),
                'file_size' => $file->getSize()
            ]);
            return json_error('Invalid image file: ' . $error, 400);
        }
        
        // Normalize to array for processing
        $validFiles = [$file];

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

        // Get alt text from form data (same for all images)
        $altText = $this->request->getPost('alt_text') ?? null;

        foreach ($validFiles as $index => $file) {
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

            // Get file extension
            $file_ext = $file->getClientExtension();
            if (empty($file_ext)) {
                $mime_to_ext = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $file_ext = $mime_to_ext[$fileMime] ?? 'jpg';
            }

            // Generate unique filename
            $unique_filename = 'var_img_' . $variationId . '_' . time() . '_' . $index . '_' . uniqid() . '.' . $file_ext;
            
            // Move uploaded file
            if (!$file->move($upload_dir, $unique_filename)) {
                $errors[] = $file->getName() . ': Failed to upload';
                continue;
            }

            // Get next display order for this variation
            $displayOrder = $this->productModel->getNextVariationImageDisplayOrder($variationId);

            // Insert image record
            $imageData = [
                'variation_id' => $variationId,
                'image' => $unique_filename,
                'alt_text' => $altText,
                'display_order' => $displayOrder,
                'status' => 'ACTIVE'
            ];

            $imageId = $this->productModel->insertVariationImage($imageData);

            if ($imageId) {
                // Return image with full URL
                $imageUrl = base_url('assets/productimages/' . $unique_filename);
                $uploadedImages[] = [
                    'id' => $imageId,
                    'image' => $unique_filename,
                    'url' => $imageUrl
                ];
            } else {
                // Delete uploaded file if database insert failed
                @unlink($upload_dir . $unique_filename);
                $errors[] = $file->getName() . ': Failed to save image record';
            }
        }

        // Return results
        if (!empty($uploadedImages)) {
            $message = count($uploadedImages) . ' image(s) uploaded successfully';
            if (!empty($errors)) {
                $message .= '. ' . count($errors) . ' file(s) failed: ' . implode(', ', $errors);
            }
            return json_success($uploadedImages, $message);
        } else {
            return json_error('Failed to upload images. ' . implode(', ', $errors), 400);
        }
    }

    /**
     * Delete variation image
     * POST /backend/variation-images/delete/{imageId}
     */
    public function delete($imageId = null)
    {
        // Get from route parameter first, then fall back to URI segment
        if (empty($imageId)) {
            $imageId = $this->request->getUri()->getSegment(4);
        }

        if (empty($imageId)) {
            return json_error('Image ID is required', 400);
        }

        $imageId = (int)$imageId;
        if ($imageId <= 0) {
            return json_error('Invalid Image ID', 400);
        }

        // Get image details
        $image = $this->productModel->getVariationImageDetails($imageId);
        if (!$image) {
            return json_error('Image not found', 404);
        }

        // Soft delete the image
        $result = $this->productModel->deleteVariationImage($imageId);

        if ($result) {
            return json_success(null, 'Image deleted successfully');
        } else {
            return json_error('Failed to delete image', 500);
        }
    }
}
