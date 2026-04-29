<?php

namespace App\Controllers;

use App\Models\HomeproductModel;
use App\Models\ProductModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Homeproducts extends BaseController
{
    use ResponseTrait;

    protected $homeproductModel;
    protected $productModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->homeproductModel = new HomeproductModel();
        $this->productModel = new ProductModel();
        helper(['api_helper']);
    }

    public function cats()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $cat_list = $this->homeproductModel->getProcateList();
        return json_success($cat_list);
    }

    public function add()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $category_id = $this->request->getPost('category_id');

            if (empty($category_id)) {
                return json_error('Category ID is required', 400);
            }

            $home_display_status = 'YES';
            $home_static_image = '';

            // Handle file upload using CI4's file handling
            $file = $this->request->getFile('home_static_image');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/category_images/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $home_static_image = base_url('assets/category_images/' . $newName);
                }
            }

            if (empty($home_static_image)) {
                return json_error('Home static image is required', 400);
            }

            $pcArr = [
                'home_display_status' => $home_display_status,
                'home_static_image' => $home_static_image
            ];

            $result = $this->productModel->updateProductCategory($category_id, $pcArr);
            
            if ($result == true) {
                return json_success(null, 'Product category has been added successfully');
            } else {
                return json_error('Failed to add product category', 500);
            }
        } else {
            // Handle GET (return category list for form)
            $cat_list = $this->homeproductModel->getProductCategoryListAll('ACTIVE');
            return json_success(['categories' => $cat_list]);
        }
    }

    public function edit()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $cId = $this->request->getUri()->getSegment(3);

        if (empty($cId)) {
            return json_error('Category ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $categoryid = $this->request->getPost('categoryid');
            $home_display_status = $this->request->getPost('home_display_status') ?: 'No';
            $sequences = $this->request->getPost('sequences') ?: '0';

            if (empty($categoryid) || $categoryid != $cId) {
                return json_error('Invalid category ID', 400);
            }

            $cArr = [
                'home_display_status' => $home_display_status,
                'sequences' => $sequences
            ];

            // Handle file upload if provided
            $file = $this->request->getFile('home_static_image');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/category_images/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $cArr['home_static_image'] = base_url('assets/category_images/' . $newName);
                }
            }

            $result = $this->productModel->updateProductCategory($cId, $cArr);
            
            if ($result == true) {
                return json_success(null, 'Product category has been updated successfully');
            } else {
                return json_error('Failed to update product category', 500);
            }
        } else {
            // Handle GET (retrieve)
            $cat_list = $this->productModel->getProductCategoryList('ACTIVE');
            $cat_details = $this->productModel->getproductCategoryDetails($cId);
            
            if ($cat_details) {
                return json_success([
                    'category' => $cat_details,
                    'categories' => $cat_list
                ]);
            } else {
                return json_error('Category not found', 404);
            }
        }
    }

    public function removed()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $cId = $this->request->getUri()->getSegment(3);

        if (empty($cId)) {
            return json_error('Category ID is required', 400);
        }

        $cArr = [
            'home_display_status' => 'NO',
            'home_static_image' => null
        ];

        $result = $this->productModel->updateProductCategory($cId, $cArr);
        
        if ($result == true) {
            return json_success(null, 'Product category has been removed successfully');
        } else {
            return json_error('Failed to remove product category', 500);
        }
    }

    public function products()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $cId = $this->request->getUri()->getSegment(3);

        $pro_list = $this->homeproductModel->getProductList($cId);
        $add_pro_list = $this->homeproductModel->getProductListfilter($cId);
        $cat_details = $cId ? $this->productModel->getproductCategoryDetails($cId) : null;
        
        return json_success([
            'products' => $pro_list,
            'available_products' => $add_pro_list,
            'category' => $cat_details,
            'has_category' => !empty($cId)
        ]);
    }

    public function addproducts()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $cId = $this->request->getUri()->getSegment(3);
        $product_id = $this->request->getPost('product_id');

        if (empty($product_id) || empty($cId)) {
            return json_error('Product ID and Category ID are required', 400);
        }

        $pArr = ['home_display_status' => 'YES'];
        $result = $this->productModel->updateProduct($product_id, $pArr);
        
        if ($result == true) {
            return json_success(null, 'Product has been added successfully');
        } else {
            return json_error('Failed to add product', 500);
        }
    }	

    public function removeproducts()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $pId = $this->request->getUri()->getSegment(3);
        $cId = $this->request->getUri()->getSegment(4);

        if (empty($pId) || empty($cId)) {
            return json_error('Product ID and Category ID are required', 400);
        }

        $pArr = ['home_display_status' => 'NO'];
        $result = $this->productModel->updateProduct($pId, $pArr);
        
        if ($result == true) {
            return json_success(null, 'Product has been removed successfully');
        } else {
            return json_error('Failed to remove product', 500);
        }
    }
}
