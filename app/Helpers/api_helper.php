<?php

/**
 * API Helper Functions for CodeIgniter 4
 * Helper functions for JSON API responses
 */

if (!function_exists('json_response')) {
    /**
     * Send JSON response
     * 
     * Note: In CI4, controllers should use $this->respond() instead.
     * This helper is provided for backward compatibility during migration.
     * 
     * @param mixed $data Response data
     * @param int $status_code HTTP status code
     * @param string $message Optional message
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    function json_response($data = null, $status_code = 200, $message = '', $success = null)
    {
        $response = service('response');
        
        // If success is explicitly set, use it; otherwise determine from status code
        // But for "Not logged in" type messages, we want success:false even with 200 status
        $is_success = $success !== null ? $success : ($status_code >= 200 && $status_code < 300);
        
        // Special case: if message indicates "not logged in" or similar, set success:false
        // CRITICAL: Ensure $message is a string before using stripos()
        if ($success === null && is_string($message) && ($message === 'Not logged in' || stripos($message, 'not logged') !== false)) {
            $is_success = false;
        }
        
        $json_data = [
            'success' => $is_success,
            'message' => $message,
            'data' => $data
        ];
        
        $json_string = json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Check if JSON encoding failed
        if ($json_string === false) {
            $json_string = json_encode([
                'success' => false,
                'message' => 'Failed to encode response data: ' . json_last_error_msg(),
                'data' => null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $status_code = 500;
        }
        
        // Ensure we have a valid JSON string
        if (empty($json_string)) {
            $json_string = json_encode([
                'success' => false,
                'message' => 'Empty response data',
                'data' => null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        $response
            ->setStatusCode($status_code)
            ->setContentType('application/json')
            ->setBody($json_string);
        
        // Verify response body is set
        if (empty($response->getBody())) {
            $response->setBody($json_string);
        }
        
        // Ensure response is sent (CodeIgniter 4 should handle this automatically, but ensure it's set)
        return $response;
    }
}

if (!function_exists('json_error')) {
    /**
     * Send JSON error response
     * 
     * Note: In CI4 controllers, use $this->fail() instead.
     * 
     * Supports two calling patterns:
     * 1. json_error($message, $status_code) - Error message with status code
     * 2. json_error($data, $status_code) - Error data (array/object) with status code
     * 
     * @param mixed $message_or_data Error message (string) or error data (array/object)
     * @param int $status_code HTTP status code
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    function json_error($message_or_data = 'An error occurred', $status_code = 400)
    {
        // If $message_or_data is an array or object, treat it as data and use default message
        if (is_array($message_or_data) || is_object($message_or_data)) {
            return json_response($message_or_data, $status_code, 'An error occurred', false);
        }
        
        // Otherwise treat it as a message string
        return json_response(null, $status_code, (string)$message_or_data, false);
    }
}

if (!function_exists('json_success')) {
    /**
     * Send JSON success response
     * 
     * Note: In CI4 controllers, use $this->respond() instead.
     * 
     * @param mixed $data Response data
     * @param string $message Success message
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    function json_success($data = null, $message = 'Success')
    {
        return json_response($data, 200, $message);
    }
}

if (!function_exists('check_auth')) {
    /**
     * Check if user is authenticated
     * 
     * Note: In CI4, use Filters instead of this helper.
     * This is provided for backward compatibility during migration.
     * 
     * @return bool Returns true if authenticated, otherwise sends JSON error and exits
     */
    function check_auth()
    {
        $session = session();
        $checkuservars = $session->get();
        
        // Check if user is logged in
        if (!isset($checkuservars['is_logged_in']) || $checkuservars['is_logged_in'] != 1) {
            $response = json_error('Unauthorized. Please login.', 401);
            $response->send();
            exit;
        }
        
        // Check usertype - allow ADMIN, SUPERADMIN, and case variations
        $usertype = isset($checkuservars['usertype']) ? strtoupper($checkuservars['usertype']) : '';
        
        if ($usertype != "SUPERADMIN" && $usertype != "ADMIN") {
            $response = json_error('Unauthorized. Admin access required. Current usertype: ' . ($usertype ?: 'none'), 403);
            $response->send();
            exit;
        }
        
        return true;
    }
}
