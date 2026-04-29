<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Session Diagnostic Controller
 * 
 * Provides endpoints to diagnose session and authentication issues.
 * Useful for debugging product add failures due to auth problems.
 */
class SessionDiagnostic extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Comprehensive session diagnostic endpoint
     * 
     * Returns detailed information about session state, cookies, and authentication.
     * This endpoint is NOT protected by SessionAuthFilter to allow diagnosis of auth issues.
     */
    public function index()
    {
        // Set Content-Type to JSON early
        $this->response->setContentType('application/json');
        
        // Get session instance
        $session = \Config\Services::session();
        $sessionData = $session->get();
        
        // Get request headers
        $cookieHeader = $this->request->getHeaderLine('Cookie') ?: 'No Cookie header present';
        $originHeader = $this->request->getHeaderLine('Origin') ?: 'No Origin header';
        
        // Parse cookies from header
        $cookies = [];
        if ($this->request->getHeaderLine('Cookie')) {
            $cookieString = $this->request->getHeaderLine('Cookie');
            $cookiePairs = explode(';', $cookieString);
            foreach ($cookiePairs as $pair) {
                $pair = trim($pair);
                if (strpos($pair, '=') !== false) {
                    list($name, $value) = explode('=', $pair, 2);
                    $cookies[trim($name)] = trim($value);
                }
            }
        }
        
        // Get session ID
        $sessionId = $session->session_id ?? 'No session ID';
        
        // Check if session file exists (for file-based sessions)
        $sessionPath = WRITEPATH . 'session/';
        $sessionFile = $sessionPath . 'ci_session_' . $sessionId;
        $sessionFileExists = file_exists($sessionFile);
        
        // Check authentication status
        $isAuthenticated = isset($sessionData['is_logged_in']) && $sessionData['is_logged_in'] == 1;
        
        // Build diagnostic response
        $diagnostic = [
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => ENVIRONMENT,
            
            'authentication' => [
                'is_authenticated' => $isAuthenticated,
                'user_id' => $sessionData['userid'] ?? null,
                'username' => $sessionData['username'] ?? null,
                'usertype' => $sessionData['usertype'] ?? null,
            ],
            
            'session' => [
                'session_id' => $sessionId,
                'session_file_exists' => $sessionFileExists,
                'session_file_path' => $sessionFile,
                'session_data_count' => count($sessionData),
                'has_logged_in_flag' => isset($sessionData['is_logged_in']),
                'logged_in_value' => $sessionData['is_logged_in'] ?? null,
            ],
            
            'cookies' => [
                'cookie_header_present' => $this->request->getHeaderLine('Cookie') !== '',
                'cookie_header' => $cookieHeader,
                'parsed_cookies' => $cookies,
                'has_ci_session_cookie' => isset($cookies['ci_session']),
                'ci_session_value_length' => isset($cookies['ci_session']) ? strlen($cookies['ci_session']) : 0,
            ],
            
            'request' => [
                'method' => $this->request->getMethod(),
                'origin' => $originHeader,
                'user_agent' => $this->request->getHeaderLine('User-Agent'),
                'referer' => $this->request->getHeaderLine('Referer') ?: 'No Referer',
            ],
            
            'diagnosis' => [],
        ];
        
        // Add diagnostic messages
        if (!$isAuthenticated) {
            $diagnostic['diagnosis'][] = '❌ User is NOT authenticated';
            
            if (!isset($cookies['ci_session'])) {
                $diagnostic['diagnosis'][] = '🔍 ROOT CAUSE: Session cookie (ci_session) is NOT being sent with request';
                $diagnostic['diagnosis'][] = '   This is why product add requests fail with 401 Unauthorized';
                $diagnostic['diagnosis'][] = '   Solution: Login to create session cookie, OR fix cross-origin cookie settings';
            } elseif (!$sessionFileExists) {
                $diagnostic['diagnosis'][] = '🔍 Session cookie exists but session file not found on server';
                $diagnostic['diagnosis'][] = '   Session may have expired or been deleted';
                $diagnostic['diagnosis'][] = '   Solution: Re-login to create new session';
            } else {
                $diagnostic['diagnosis'][] = '🔍 Session cookie exists and file found, but is_logged_in flag not set';
                $diagnostic['diagnosis'][] = '   Session exists but user not logged in';
                $diagnostic['diagnosis'][] = '   Solution: Login with valid credentials';
            }
        } else {
            $diagnostic['diagnosis'][] = '✅ User is authenticated';
            $diagnostic['diagnosis'][] = '✅ Session cookie is being sent correctly';
            $diagnostic['diagnosis'][] = '✅ Product add requests should work';
        }
        
        // Add session data (sanitized - no sensitive info)
        $diagnostic['session']['keys'] = array_keys($sessionData);
        
        return json_success($diagnostic, 'Session diagnostic complete');
    }

    /**
     * Quick session status check
     * 
     * Simple endpoint that returns just authenticated status.
     */
    public function status()
    {
        $this->response->setContentType('application/json');
        
        $session = \Config\Services::session();
        $sessionData = $session->get();
        
        $isAuthenticated = isset($sessionData['is_logged_in']) && $sessionData['is_logged_in'] == 1;
        
        $status = [
            'authenticated' => $isAuthenticated,
            'user_id' => $sessionData['userid'] ?? null,
            'username' => $sessionData['username'] ?? null,
            'session_id' => $session->session_id ?? null,
        ];
        
        if ($isAuthenticated) {
            return json_success($status, 'User is authenticated');
        } else {
            return json_response($status, 200, 'User is not authenticated', false);
        }
    }
}
