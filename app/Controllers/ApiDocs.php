<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * API Documentation Controller
 * Serves Swagger/OpenAPI documentation for backend admin API
 */
class ApiDocs extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * Display Swagger UI
     * GET /backend/api-docs
     */
    public function index()
    {
        // In CI4, views are handled differently
        // For now, return a simple response indicating API docs endpoint
        // You may need to create a view file for Swagger UI
        return $this->respond([
            'message' => 'API Documentation endpoint',
            'swagger_json_url' => base_url('api-docs/swagger.json'),
            'note' => 'Swagger UI view needs to be created separately'
        ]);
    }

    /**
     * Serve swagger.json with environment-specific server URLs
     * GET /backend/api-docs/swagger.json
     */
    public function json()
    {
        // Load the base swagger.json file
        $swagger_file = FCPATH . 'api-docs/swagger.json';
        
        if (!file_exists($swagger_file)) {
            return $this->respond([
                'error' => 'Swagger specification not found'
            ], 404);
        }

        // Read and decode the swagger.json
        $swagger_content = file_get_contents($swagger_file);
        $swagger = json_decode($swagger_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->respond([
                'error' => 'Invalid JSON in swagger.json'
            ], 500);
        }

        // Detect environment and update servers array
        $is_local = $this->isLocalEnvironment();
        
        $servers = [];
        
        if ($is_local) {
            // Local development servers
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_url = $protocol . '://' . $host . '/backend';
            
            $servers[] = [
                'url' => $base_url,
                'description' => 'Local Development Server'
            ];
            
            // Also include production for reference
            $domains = config('Domains');
            $servers[] = [
                'url' => $domains->getBackendBaseUrl(),
                'description' => 'Production Server (Reference)'
            ];
        } else {
            // Production servers
            $domains = config('Domains');
            $servers[] = [
                'url' => $domains->getBackendBaseUrl(),
                'description' => 'Production Server'
            ];
            
            // Optionally include local for development reference
            $servers[] = [
                'url' => 'http://localhost/backend',
                'description' => 'Local Development Server (Reference)'
            ];
        }

        // Update the servers in swagger spec
        $swagger['servers'] = $servers;

        // Set headers and output
        // Note: CORS headers are handled globally by CorsFilter - don't set them here to avoid conflicts
        $response = service('response');
        $response->setContentType('application/json');
        $response->setBody(json_encode($swagger, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        
        return $response;
    }

    /**
     * Check if running in local environment
     */
    private function isLocalEnvironment()
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        
        return (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            $server_name === 'localhost' ||
            $server_name === '127.0.0.1' ||
            strpos($server_name, 'localhost') !== false
        );
    }
}
