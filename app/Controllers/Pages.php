<?php

namespace App\Controllers;

use App\Models\PagesModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Pages extends BaseController
{
    use ResponseTrait;

    protected $pagesModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->pagesModel = new PagesModel();
        helper(['api_helper']);
    }

    public function edit()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $pageId = $this->request->getUri()->getSegment(3);

        if (empty($pageId)) {
            return json_error('Page ID is required', 400);
        }

        // Handle POST (update) - Using CI4 recommended method
        if ($this->request->is('post')) {
            $json = $this->request->getJSON(true);
            $page_title = $json['page_title'] ?? $this->request->getPost('page_title');
            $page_content = $json['page_content'] ?? $this->request->getPost('page_content');

            if (empty($page_title)) {
                return json_error('Page title is required', 400);
            }

            $valids_pagetitle = $this->pagesModel->pageTileExists($pageId, $page_title);
            if (isset($valids_pagetitle) && count($valids_pagetitle) > 0) {
                return json_error('Page title already exists', 400);
            }

            $pageArr = [
                'page_title' => $page_title,
                'page_content' => $page_content
            ];

            $result = $this->pagesModel->updatePage($pageId, $pageArr);
            
            if ($result == true) {
                return json_success(null, 'Page has been updated successfully');
            } else {
                return json_error('Failed to update page', 500);
            }
        } else {
            // Handle GET (retrieve)
            $page_details = $this->pagesModel->getPageDetails($pageId);
            
            if ($page_details) {
                return json_success($page_details);
            } else {
                return json_error('Page not found', 404);
            }
        }
    }
}
