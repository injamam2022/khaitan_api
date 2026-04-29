<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Simple Test Controller
 * 
 * Returns "hello backend" to verify the backend is running on the server.
 * This is a security check endpoint - no sensitive information exposed.
 */
class Test extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * Simple test endpoint
     * Returns "hello backend" to verify server is running
     * 
     * Access: GET /backend/test
     */
    public function index()
    {
        // Set plain text response
        $this->response->setContentType('text/plain');
        
        // Return simple message
        return "hello backend";
    }
}
