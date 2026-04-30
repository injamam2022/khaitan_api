<?php

namespace App\Controllers;

use App\Models\BannerModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Banners extends BaseController
{
    use ResponseTrait;

    protected $bannerModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->bannerModel = new BannerModel();
        helper(['api_helper']);
    }

    /**
     * Get list of all banners
     */
    public function banners()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $banner_list = $this->bannerModel->getBannerList();
        return json_success($banner_list);
    }

    /**
     * Public storefront endpoint.
     * Compatible with UI call: GET /api/home/banners/v2
     */
    public function bannersV2()
    {
        try {
            $banner_list = $this->bannerModel->getBannerList();
            $active = array_values(array_filter($banner_list, static function (array $row): bool {
                return (int)($row['is_active'] ?? 1) === 1;
            }));

            return json_success($active);
        } catch (\Throwable $e) {
            log_message('error', 'Banners::bannersV2 error: ' . $e->getMessage());
            return json_error('Failed to fetch banners', 500);
        }
    }

    /**
     * Add a new banner
     */
    public function add()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        // Handle POST (create)
        if ($this->request->is('post')) {
            $alt = $this->request->getPost('alt');
            $link = $this->request->getPost('link');
            $order = $this->request->getPost('order') ? (int)$this->request->getPost('order') : 0;
            $is_active = $this->request->getPost('is_active') !== null ? (int)$this->request->getPost('is_active') : 1;

            if (empty($alt)) {
                return json_error('Alt text is required', 400);
            }

            $image_url = '';

            // Handle file upload using CI4's file handling
            $file = $this->request->getFile('img');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/banners/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $image_url = base_url('assets/banners/' . $newName);
                }
            }

            if (empty($image_url)) {
                return json_error('Banner image is required', 400);
            }

            $bannerArr = [
                'img' => $image_url,
                'alt' => $alt,
                'link' => $link ?: null,
                'order' => $order,
                'is_active' => $is_active,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $result = $this->bannerModel->insertBanner($bannerArr);
            
            if ($result > 0) {
                return json_success(['id' => $result], 'Banner has been added successfully');
            } else {
                return json_error('Failed to add banner', 500);
            }
        } else {
            // Handle GET
            return json_success(null, 'Add banner endpoint. Send POST with alt, link (optional), order (optional), is_active (optional), and img file');
        }
    }

    /**
     * Edit an existing banner
     */
    public function edit()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $bannerId = $this->request->getUri()->getSegment(3);

        if (empty($bannerId)) {
            return json_error('Banner ID is required', 400);
        }

        // Handle POST (update)
        if ($this->request->is('post')) {
            $alt = $this->request->getPost('alt');
            $link = $this->request->getPost('link');
            $order = $this->request->getPost('order') !== null ? (int)$this->request->getPost('order') : null;
            $is_active = $this->request->getPost('is_active') !== null ? (int)$this->request->getPost('is_active') : null;

            if (empty($alt)) {
                return json_error('Alt text is required', 400);
            }

            $bannerArr = [
                'alt' => $alt,
                'link' => $link !== null ? ($link ?: null) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($order !== null) {
                $bannerArr['order'] = $order;
            }

            if ($is_active !== null) {
                $bannerArr['is_active'] = $is_active;
            }

            // Handle file upload if provided
            $file = $this->request->getFile('img');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/banners/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $bannerArr['img'] = base_url('assets/banners/' . $newName);
                }
            }

            $result = $this->bannerModel->updateBanner($bannerId, $bannerArr);
            
            if ($result == true) {
                return json_success(null, 'Banner has been updated successfully');
            } else {
                return json_error('Failed to update banner', 500);
            }
        } else {
            // Handle GET (retrieve)
            $banner_details = $this->bannerModel->getBannerDetails($bannerId);
            
            if ($banner_details) {
                return json_success($banner_details);
            } else {
                return json_error('Banner not found', 404);
            }
        }
    }

    /**
     * Delete a banner
     */
    public function removed()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $bannerId = $this->request->getUri()->getSegment(3);

        if (empty($bannerId)) {
            return json_error('Banner ID is required', 400);
        }

        $result = $this->bannerModel->deleteBanner($bannerId);
        
        if ($result == true) {
            return json_success(null, 'Banner has been removed successfully');
        } else {
            return json_error('Failed to remove banner', 500);
        }
    }
}
