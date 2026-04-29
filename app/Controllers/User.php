<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class User extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * GET: List all users
     */
    public function index()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $db = \Config\Database::connect();
        $builder = $db->table('users');
        $users = $builder->get()->getResultArray();
        
        return json_success($users);
    }

    /**
     * POST: Create a new user
     * 
     * Expected JSON body:
     * {
     *   "fullname": "John Doe",
     *   "email": "john@example.com",
     *   "phonenumber": "1234567890",
     *   "password": "password123" (optional, will be hashed with MD5)
     *   "status": "ACTIVE" (optional, defaults to ACTIVE)
     * }
     */
    public function create()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        // Using CI4 recommended method check
        if (!$this->request->is('post')) {
            return json_error('Method not allowed', 405);
        }

        // Get JSON input
        $json = $this->request->getJSON(true);
        
        // Validate required fields
        if (empty($json['fullname'])) {
            return json_error('Fullname is required', 400);
        }

        // Prepare user data
        $user_data = [
            'fullname' => $json['fullname'],
            'email' => $json['email'] ?? null,
            'phonenumber' => $json['phonenumber'] ?? null,
            'status' => $json['status'] ?? 'ACTIVE',
            'created_date' => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'),
            'is_logged_in' => 0
        ];

        // Hash password if provided (use bcrypt for secure hashing)
        if (!empty($json['password'])) {
            $user_data['password'] = password_hash($json['password'], PASSWORD_BCRYPT);
        }

        // Validate status
        if (!in_array($user_data['status'], ['ACTIVE', 'INACTIVE', 'DELETED'])) {
            return json_error('Invalid status. Must be ACTIVE, INACTIVE, or DELETED', 400);
        }

        $db = \Config\Database::connect();
        $builder = $db->table('users');

        // Check if email already exists (if provided)
        if (!empty($user_data['email'])) {
            $builder->where('email', $user_data['email']);
            $builder->where('status !=', 'DELETED');
            $existing = $builder->get()->getRowArray();
            if ($existing) {
                return json_error('Email already exists', 409);
            }
        }

        // Check if phone number already exists (if provided)
        if (!empty($user_data['phonenumber'])) {
            $builder = $db->table('users');
            $builder->where('phonenumber', $user_data['phonenumber']);
            $builder->where('status !=', 'DELETED');
            $existing = $builder->get()->getRowArray();
            if ($existing) {
                return json_error('Phone number already exists', 409);
            }
        }

        // Insert user
        $builder = $db->table('users');
        $result = $builder->insert($user_data);
        
        if ($result) {
            $id = $db->insertID();
            // Get the created user
            $new_user = $builder->where('id', $id)->get()->getRowArray();
            return json_success($new_user, 'User created successfully');
        } else {
            return json_error('Failed to create user', 500);
        }
    }
}
