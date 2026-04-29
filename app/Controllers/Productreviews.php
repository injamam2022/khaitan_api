<?php

namespace App\Controllers;

use App\Models\ProductreviewsModel;
use App\Models\ProductModel;
use App\Models\OrderModel;
use CodeIgniter\HTTP\ResponseInterface;

class Productreviews extends BaseController
{
    public function __construct()
    {
        helper('api');
        // Authentication is handled by SessionAuthFilter
        // No need to call check_auth() here as it would run before the filter
    }
    
    public function reviews()
    {
        $model = model(ProductreviewsModel::class);
        $reviewList = $model->getReviewList();
        
        return $this->respond([
            'success' => true,
            'data' => $reviewList,
            'message' => 'Success'
        ]);
    }

    /**
     * POST: Add a new review
     * GET: Get product list for form
     * 
     * Expected JSON body (POST):
     * {
     *   "product_id": 1,
     *   "review": "Great product!",
     *   "customer_name": "John Doe",
     *   "star_ratting": 5 (optional, 1-5)
     * }
     */
    public function add()
    {
        $productModel = model(ProductModel::class);
        $orderModel = model(OrderModel::class);
        
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            // Get JSON input first (for API requests)
            $json = $this->request->getJSON(true);
            
            // Use JSON data if available, otherwise fall back to POST
            $product_id = $json['product_id'] ?? $this->request->getPost('product_id');
            $review = $json['review'] ?? $this->request->getPost('review');
            $customer_name = $json['customer_name'] ?? $this->request->getPost('customer_name');
            $star_ratting = $json['star_ratting'] ?? $json['rating'] ?? $this->request->getPost('star_ratting') ?? $this->request->getPost('rating') ?? null;
            
            $post_user_id = $this->request->getPost('user_id');
            $user_id = isset($json['user_id']) ? (int)($json['user_id']) : ($post_user_id ? (int)$post_user_id : null);
            
            if (empty($product_id) || empty($review)) {
                return $this->fail('Product ID and review are required', 400);
            }
            
            // Validate review text length (max 5000 characters)
            if (strlen($review) > 5000) {
                return $this->fail('Review text must be 5000 characters or less', 400);
            }
            
            // Customer name is optional - use provided name or default to empty string
            if (empty($customer_name)) {
                $customer_name = ''; // Allow empty customer name
            }

            // Validate rating if provided (1-5)
            if ($star_ratting !== null) {
                $star_ratting = (int)$star_ratting;
                if ($star_ratting < 1 || $star_ratting > 5) {
                    return $this->fail('Rating must be between 1 and 5', 400);
                }
            }

            // If user_id is provided, validate purchase
            if ($user_id !== null && $user_id > 0) {
                // Check if user has purchased this product
                $db = \Config\Database::connect();
                $builder = $db->table('orders');
                $builder->select('orders.id');
                $builder->join('order_items', 'orders.id = order_items.order_id');
                $builder->where('orders.user_id', $user_id);
                $builder->where('order_items.product_id', (int)$product_id);
                $builder->whereIn('orders.status', ['CONFIRMED', 'DELIVERED', 'COMPLETED']);
                $purchase = $builder->get()->getRow();
                
                if (!$purchase) {
                    return $this->fail('User must have purchased this product before reviewing', 400);
                }

                // Check for existing review by this user for this product
                $reviewModel = model(ProductreviewsModel::class);
                $existing_review = $reviewModel->where('product_id', (int)$product_id)
                    ->where('user_id', $user_id)
                    ->where('status <>', 'DELETED')
                    ->first();
            } else {
                // No user_id - admin creating testimonial/review not tied to purchase
                $existing_review = null;
            }

            $session = session();
            $checkuservars = $session->get();
            $created_id = $checkuservars['userid'] ?? null;
            $created_on = date('Y-m-d H:i:s');
            $status = 'ACTIVE';

            // Prepare review data according to database schema
            $prArr = [
                'product_id' => $product_id,
                'review' => $review,
                'customer_name' => $customer_name,
                'review_added_by' => 'ADMIN', // Admin-created reviews
                'created_id' => $created_id,
                'created_on' => $created_on,
                'status' => $status
            ];

            // Set user_id if provided
            if ($user_id !== null && $user_id > 0) {
                $prArr['user_id'] = $user_id;
            }
            
            // Add rating if provided
            if ($star_ratting !== null) {
                $prArr['star_ratting'] = $star_ratting;
            }
            
            // Update existing review if found, otherwise insert new
            $reviewModel = model(ProductreviewsModel::class);
            if (!empty($existing_review) && isset($existing_review['id'])) {
                // Update existing review
                $result = $reviewModel->updateProductReview($existing_review['id'], $prArr);
                if ($result) {
                    return $this->respond([
                        'success' => true,
                        'data' => ['id' => $existing_review['id']],
                        'message' => 'Product review has been updated successfully'
                    ]);
                } else {
                    return $this->fail('Failed to update product review', 500);
                }
            } else {
                // Insert new review
                $result = $reviewModel->insertProductReview($prArr);
                if ($result > 0) {
                    return $this->respond([
                        'success' => true,
                        'data' => ['id' => $result],
                        'message' => 'Product review has been added successfully'
                    ]);
                } else {
                    return $this->fail('Failed to add product review', 500);
                }
            }
        } else {
            // Handle GET (return product list for form)
            $product_list = $productModel->getProductList('ACTIVE');
            return $this->respond([
                'success' => true,
                'data' => ['products' => $product_list],
                'message' => 'Success'
            ]);
        }
    }

    public function edit()
    {
        $productModel = model(ProductModel::class);
        
        $prId = $this->request->getUri()->getSegment(3);

        if (empty($prId)) {
            return $this->fail('Review ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            // Get JSON input first (for API requests)
            $json = $this->request->getJSON(true);
            
            // Use JSON data if available, otherwise fall back to POST
            $product_id = isset($json['product_id']) ? $json['product_id'] : $this->request->getPost('product_id');
            $review = isset($json['review']) ? $json['review'] : $this->request->getPost('review');
            $customer_name = isset($json['customer_name']) ? $json['customer_name'] : $this->request->getPost('customer_name');
            $status = isset($json['status']) ? $json['status'] : ($this->request->getPost('status') ?: 'INACTIVE');

            if (empty($product_id) || empty($review)) {
                return $this->fail('Product ID and review are required', 400);
            }
            
            // Validate review text length (max 5000 characters)
            if (strlen($review) > 5000) {
                return $this->fail('Review text must be 5000 characters or less', 400);
            }
            
            // Customer name is optional - use provided name or default to empty string
            if (empty($customer_name)) {
                $customer_name = ''; // Allow empty customer name
            }

            $prrArr = [
                'product_id' => $product_id,
                'customer_name' => $customer_name,
                'review' => $review,
                'status' => $status
            ];

            $reviewModel = model(ProductreviewsModel::class);
            $result = $reviewModel->updateProductReview($prId, $prrArr);
            
            if ($result == true) {
                return $this->respond([
                    'success' => true,
                    'data' => null,
                    'message' => 'Product review has been updated successfully'
                ]);
            } else {
                return $this->fail('Failed to update product review', 500);
            }
        } else {
            // Handle GET (retrieve review details)
            $reviewModel = model(ProductreviewsModel::class);
            $review_details = $reviewModel->getReviewDetails($prId);
            $product_list = $productModel->getProductList('ACTIVE');
            
            if ($review_details) {
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'review' => $review_details,
                        'products' => $product_list
                    ],
                    'message' => 'Success'
                ]);
            } else {
                return $this->fail('Review not found', 404);
            }
        }
    }

    /**
     * DELETE: Remove a review (soft delete - sets status to DELETED)
     * 
     * Accepts review ID from URI segment or POST body
     */
    public function removed()
    {
        // Get review ID from URI segment or POST body
        $prId = $this->request->getUri()->getSegment(3);
        
        // If not in URI, try POST body
        if (empty($prId)) {
            $json = $this->request->getJSON(true);
            $prId = $json['id'] ?? $this->request->getPost('id') ?? null;
        }

        if (empty($prId)) {
            return $this->fail('Review ID is required', 400);
        }

        $prArr = ['status' => 'DELETED'];
        $reviewModel = model(ProductreviewsModel::class);
        $result = $reviewModel->updateProductReview($prId, $prArr);
        
        if ($result == true) {
            return $this->respond([
                'success' => true,
                'data' => null,
                'message' => 'Product review has been removed successfully'
            ]);
        } else {
            return $this->fail('Failed to remove product review', 500);
        }
    }
}
