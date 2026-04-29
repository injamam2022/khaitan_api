<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Jwt;

/**
 * JWT Authentication Filter
 * 
 * Validates JWT tokens from Authorization header and sets user context.
 * This filter runs before controller execution to authenticate requests.
 * 
 * Migrated from CI3 JwtAuthHook to CI4 Filter.
 */
class JwtAuthFilter implements FilterInterface
{
    private $publicRoutes = [
        'login',
        'health',
        'setup',
        'testdb'
    ];

    /**
     * Before filter - runs before controller execution
     * 
     * @param RequestInterface $request
     * @param array|null $arguments
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Skip authentication for public routes
        $uri = service('uri');
        $controller = $uri->getSegment(1) ?? '';
        
        // Check if this is a public route
        if (in_array(strtolower($controller), $this->publicRoutes)) {
            return;
        }
        
        // Skip OPTIONS requests (CORS preflight) - CodeIgniter returns uppercase
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return;
        }
        
        // Get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization') ?? $request->getHeaderLine('X-Authorization') ?? '';
        
        // Try alternative methods if header not found
        if (empty($authHeader)) {
            // Check $_SERVER directly (Apache might not pass Authorization header)
            if (isset($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                if (isset($headers['Authorization']) && !empty($headers['Authorization'])) {
                    $authHeader = $headers['Authorization'];
                } elseif (isset($headers['authorization']) && !empty($headers['authorization'])) {
                    $authHeader = $headers['authorization'];
                }
            } elseif (function_exists('getallheaders')) {
                $headers = getallheaders();
                if (isset($headers['Authorization']) && !empty($headers['Authorization'])) {
                    $authHeader = $headers['Authorization'];
                } elseif (isset($headers['authorization']) && !empty($headers['authorization'])) {
                    $authHeader = $headers['authorization'];
                }
            }
        }
        
        $jwtValid = false;
        
        // If JWT token is provided, validate it
        if (!empty($authHeader)) {
            // Extract token (format: "Bearer <token>" or just "<token>")
            $token = null;
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = $authHeader;
            }
            
            if (!empty($token)) {
                try {
                    // Load JWT library
                    $jwt = new Jwt();
                    
                    // Decode and validate token
                    $payload = $jwt->decode($token);
                    
                    if ($payload !== false && isset($payload['type']) && $payload['type'] === 'access') {
                        // Valid JWT token - set user context in session
                        $session = \Config\Services::session();
                        $sessionUser = [
                            'id' => $payload['user_id'] ?? null,
                            'userid' => $payload['user_id'] ?? null,
                            'username' => $payload['username'] ?? null,
                            'usertype' => $payload['usertype'] ?? null,
                            'fullname' => $payload['fullname'] ?? null,
                            'is_logged_in' => 1,
                            'jwt_payload' => $payload  // Store full payload for reference
                        ];
                        $session->set($sessionUser);
                        $jwtValid = true;
                    }
                } catch (\Exception $e) {
                    // JWT validation failed - log for debugging
                    log_message('debug', 'JWT validation error: ' . $e->getMessage());
                } catch (\Error $e) {
                    // Handle PHP 7+ Error exceptions
                    log_message('debug', 'JWT validation error (Error): ' . $e->getMessage());
                }
            }
        }
        
        // If JWT was valid, we're done
        if ($jwtValid) {
            return;
        }
        
        // No JWT token or invalid JWT - fall back to session-based auth
        $session = \Config\Services::session();
        $checkuservars = $session->get();
        
        // Check if user is logged in via session
        if (isset($checkuservars['is_logged_in']) && $checkuservars['is_logged_in'] == 1) {
            // Session-based auth is valid - allow request to proceed
            return;
        }
        
        // Neither JWT nor session auth is valid - return unauthorized
        return $this->unauthorized($request);
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
