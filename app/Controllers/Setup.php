<?php

namespace App\Controllers;

use App\Models\ProfileModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Setup Endpoint - Create/Verify Admin User
 * Use this to ensure the admin user exists with correct password
 */
class Setup extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Create or update admin user
     * POST only: {"username": "admin@example.com", "password": "secure_password", "usertype": "SUPERADMIN"}
     * 
     * SECURITY: This endpoint is disabled in production. Use only in development/testing.
     */
    public function index()
    {
        // Disable in production for security
        if (ENVIRONMENT === 'production') {
            return json_error('Setup endpoint disabled in production', 403);
        }
        
        try {
            $db = \Config\Database::connect();
            
            // Require POST method only (no GET requests) - Using CI4 recommended method
            if (!$this->request->is('post')) {
                return json_error('POST method required. This endpoint does not accept GET requests.', 405);
            }
            
            // Get credentials from POST only (no defaults)
            $json = $this->request->getJSON(true);
            $username = $json['username'] ?? '';
            $password = $json['password'] ?? '';
            $usertype = $json['usertype'] ?? 'SUPERADMIN';
            
            // Validate required fields
            if (empty($username) || empty($password)) {
                return json_error('Username and password are required', 400);
            }
            
            // Validate username format (basic email validation)
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
                return json_error('Invalid username format. Must be a valid email address.', 400);
            }
            
            // Validate password strength (minimum 6 characters)
            if (strlen($password) < 6) {
                return json_error('Password must be at least 6 characters long', 400);
            }
            
            // Check if user exists
            $builder = $db->table('admin_login');
            $builder->where('username', $username);
            $exists = $builder->countAllResults() > 0;
            
            // Use bcrypt for secure password hashing
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            
            if ($exists) {
                // Update existing user
                $builder = $db->table('admin_login');
                $builder->where('username', $username);
                $builder->update([
                    'password' => $password_hash,
                    'usertype' => $usertype,
                    'row_status' => 'ACTIVE'
                ]);
                $action = 'updated';
            } else {
                // Create new user
                $builder = $db->table('admin_login');
                $builder->insert([
                    'username' => $username,
                    'password' => $password_hash,
                    'usertype' => $usertype,
                    'row_status' => 'ACTIVE',
                    'created_on' => date('Y-m-d H:i:s')
                ]);
                $action = 'created';
            }
            
            // Verify
            $builder = $db->table('admin_login');
            $builder->where('username', $username);
            $user = $builder->get()->getRowArray();
            
            return json_success([
                'action' => $action,
                'username' => $username,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'usertype' => $user['usertype'] ?? 'N/A',
                    'row_status' => $user['row_status'] ?? 'N/A'
                ],
                'message' => "User {$action} successfully. You can now login with username: {$username}"
            ], "User {$action} successfully");
            
        } catch (\Exception $e) {
            return json_error('Setup failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Test login with provided credentials
     * POST: {"username": "admin@example.com", "password": "your_password"}
     * 
     * SECURITY: This endpoint is disabled in production. Use only in development/testing.
     */
    public function testlogin()
    {
        // Disable in production for security
        if (ENVIRONMENT === 'production') {
            return json_error('Test login endpoint disabled in production', 403);
        }
        
        $json = $this->request->getJSON(true);
        $username = $json['username'] ?? '';
        $password = $json['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            return json_error('Username and password are required', 400);
        }
        
        try {
            $profileModel = new ProfileModel();

            $user = $profileModel->validateLogin($username, $password);
            
            if (is_object($user)) {
                return json_success([
                    'login_success' => true,
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'usertype' => $user->user_type ?? $user->usertype ?? 'N/A'
                    ]
                ], 'Login test successful');
            } else {
                return json_error('Login test failed - Invalid username or password', 401);
            }
            
        } catch (\Exception $e) {
            return json_error('Login test error: ' . $e->getMessage(), 500);
        }
    }
}
