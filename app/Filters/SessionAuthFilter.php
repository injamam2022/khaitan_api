<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Session Authentication Filter
 * 
 * Validates session-based authentication for protected routes.
 * This filter runs before controller execution to authenticate requests.
 */
class SessionAuthFilter implements FilterInterface
{
    private $publicRoutes = [
        'login',
        'health', // Health check is public (production-safe)
        'test' // Simple test endpoint to verify backend is running
        // Debug/test routes removed for production security
    ];

    /**
     * Before filter - runs before controller execution
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $uri = service('uri');
        $segments = $uri->getSegments();
        $seg1 = strtolower((string) ($segments[0] ?? ''));
        $seg2 = strtolower((string) ($segments[1] ?? ''));
        // Public: login, health, test (path may be "login" or "backend/login")
        if (in_array($seg1, $this->publicRoutes) || in_array($seg2, $this->publicRoutes)) {
            return;
        }
        // EasyEcom webhooks (incoming from EasyEcom; no session)
        if ($seg1 === 'webhooks' && $seg2 === 'easyecom') {
            return;
        }
        // EasyEcom order push (called by API with X-EasyEcom-Push-Secret)
        if ($seg1 === 'orders' && $seg2 === 'push-easyecom') {
            return;
        }
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return;
        }

        try {
            $session = \Config\Services::session();
            $checkuservars = $session->get();
        } catch (\Throwable $e) {
            log_message('error', 'SessionAuthFilter session - ' . $e->getMessage());
            // GET to login: let controller return 200 "Not logged in" instead of 401
            if (strtoupper($request->getMethod()) === 'GET' && ($seg1 === 'login' || $seg2 === 'login')) {
                return;
            }
            return $this->unauthorized($request);
        }
        
        // Debug: Log session check (only in development)
        if (ENVIRONMENT === 'development') {
            log_message('debug', 'SessionAuthFilter: Checking session', [
                'session_id' => $session->session_id ?? 'N/A',
                'has_session_data' => !empty($checkuservars),
                'is_logged_in' => $checkuservars['is_logged_in'] ?? 'NOT SET',
                'cookie_received' => isset($_COOKIE['ci_session']),
            ]);
        }
        
        // Check if user is logged in via session
        if (!isset($checkuservars['is_logged_in']) || $checkuservars['is_logged_in'] != 1) {
            return $this->unauthorized($request);
        }

        $now = time();
        
        // Inactivity timeout: Configurable via environment variable, defaults to 1 hour (3600s) for normal requests
        // Session expiration is 7200s (2 hours), so inactivity timeout should be less than that
        // For upload endpoints, use longer timeout to prevent timeout during long uploads
        $defaultInactivityTimeout = (int)(getenv('SESSION_INACTIVITY_TIMEOUT') ?: $_ENV['SESSION_INACTIVITY_TIMEOUT'] ?? 3600); // 1 hour default
        $uploadInactivityTimeout = (int)(getenv('SESSION_UPLOAD_TIMEOUT') ?: $_ENV['SESSION_UPLOAD_TIMEOUT'] ?? 7200); // 2 hours for uploads
        
        // Check if this is an upload endpoint (POST with FormData or file upload)
        $isUploadRequest = false;
        if (strtoupper($request->getMethod()) === 'POST') {
            $path = strtolower($uri->getPath() ?? '');
            $uploadPaths = ['products/add', 'products/edit', 'products/catalog', 'product-images', 'variation-images',
                           'product-description-images', 'brochure', 'specification'];
            foreach ($uploadPaths as $uploadPath) {
                if (strpos($path, $uploadPath) !== false) {
                    $isUploadRequest = true;
                    break;
                }
            }
            // Also check if request has file uploads
            if (!$isUploadRequest && $request->getFiles() && count($request->getFiles()) > 0) {
                $isUploadRequest = true;
            }
        }
        
        $inactivitySeconds = $isUploadRequest ? $uploadInactivityTimeout : $defaultInactivityTimeout;
        $lastActivity = isset($checkuservars['last_activity']) ? (int) $checkuservars['last_activity'] : 0;

        // For upload requests, update last_activity FIRST to prevent timeout during long uploads
        // For normal requests, check expiration first
        if ($isUploadRequest) {
            // Update immediately for uploads to prevent timeout during the upload process
            $session->set('last_activity', $now);
        }

        // Check expiration (use lastActivity before update for normal requests)
        if ($lastActivity > 0 && ($now - $lastActivity) > $inactivitySeconds) {
            $session->destroy();
            return $this->unauthorized($request);
        }
        
        // Update last_activity for normal requests (already updated for uploads above)
        if (!$isUploadRequest) {
            $session->set('last_activity', $now);
        }
        
        return;
    }

    /**
     * After filter - runs after controller execution
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return mixed
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after
        return $response;
    }

    /**
     * Send unauthorized response
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function unauthorized(RequestInterface $request)
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setContentType('application/json');
        
        // CORS headers are handled by CorsFilter - don't set them here to avoid conflicts
        
        // Send JSON error response
        $response->setBody(json_encode([
            'success' => false,
            'message' => 'Unauthorized. Please login.',
            'data' => null
        ]));
        
        return $response;
    }
}
