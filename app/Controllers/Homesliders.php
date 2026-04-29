<?php

namespace App\Controllers;

use App\Models\SliderModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Homesliders extends BaseController
{
    use ResponseTrait;

    protected $sliderModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->sliderModel = new SliderModel();
        helper(['api_helper']);
    }

    public function sliders()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $slider_list = $this->sliderModel->getSliderList();
        return json_success($slider_list);
    }

    public function add()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        // Handle POST (create) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $title = $this->request->getPost('title');
            $description = $this->request->getPost('description');

            if (empty($title) || empty($description)) {
                return json_error('Title and description are required', 400);
            }

            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $created_id = $checkuservars['userid'] ?? null;
            $created_on = date('Y-m-d H:i:s');
            $status = 'ACTIVE';
            $image_url = '';

            // Handle file upload using CI4's file handling
            $file = $this->request->getFile('image_url');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/homesliders/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $image_url = base_url('assets/homesliders/' . $newName);
                }
            }

            if (empty($image_url)) {
                return json_error('Image is required', 400);
            }

            $sliderArr = [
                'title' => $title,
                'description' => $description,
                'image_url' => $image_url,
                'created_id' => $created_id,
                'created_on' => $created_on,
                'status' => $status
            ];

            $result = $this->sliderModel->insertSlider($sliderArr);
            
            if ($result > 0) {
                return json_success(['id' => $result], 'Slider has been added successfully');
            } else {
                return json_error('Failed to add slider', 500);
            }
        } else {
            // Handle GET
            return json_success(null, 'Add slider endpoint. Send POST with title, description, and image_url file');
        }
    }

    public function edit()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $sId = $this->request->getUri()->getSegment(3);

        if (empty($sId)) {
            return json_error('Slider ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $title = $this->request->getPost('title');
            $description = $this->request->getPost('description');
            $status = $this->request->getPost('status') ?: 'INACTIVE';

            if (empty($title) || empty($description)) {
                return json_error('Title and description are required', 400);
            }

            $sliderArr = [
                'title' => $title,
                'description' => $description,
                'status' => $status,
            ];

            // Handle file upload if provided
            $file = $this->request->getFile('image_url');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = FCPATH . 'assets/homesliders/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadPath, $newName)) {
                    $sliderArr['image_url'] = base_url('assets/homesliders/' . $newName);
                }
            }

            $result = $this->sliderModel->updateSlider($sId, $sliderArr);
            
            if ($result == true) {
                return json_success(null, 'Slider has been updated successfully');
            } else {
                return json_error('Failed to update slider', 500);
            }
        } else {
            // Handle GET (retrieve)
            $slider_details = $this->sliderModel->getSliderDetails($sId);
            
            if ($slider_details) {
                return json_success($slider_details);
            } else {
                return json_error('Slider not found', 404);
            }
        }
    }

    public function removed()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $sId = $this->request->getUri()->getSegment(3);

        if (empty($sId)) {
            return json_error('Slider ID is required', 400);
        }

        $sliderArr = ['status' => 'DELETED'];
        $result = $this->sliderModel->updateSlider($sId, $sliderArr);
        
        if ($result == true) {
            return json_success(null, 'Slider has been removed successfully');
        } else {
            return json_error('Failed to remove slider', 500);
        }
    }
}
