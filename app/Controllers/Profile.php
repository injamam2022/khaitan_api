<?php

namespace App\Controllers;

use App\Models\ProfileModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Profile extends BaseController
{
    use ResponseTrait;

    protected $profileModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->profileModel = new ProfileModel();
        helper(['api_helper']);
    }

    public function changepass()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        // Handle POST requests - Using CI4 recommended method
        if ($this->request->is('post')) {
            $json = $this->request->getJSON(true);
            $oldpass = $json['oldpass'] ?? $this->request->getPost('oldpass');
            $newpass = $json['newpass'] ?? $this->request->getPost('newpass');
            $retypenewpass = $json['retypenewpass'] ?? $this->request->getPost('retypenewpass');

            if (empty($oldpass) || empty($newpass) || empty($retypenewpass)) {
                return json_error('All password fields are required', 400);
            }

            if ($newpass !== $retypenewpass) {
                return json_error('New password and re-typed password do not match', 400);
            }

            $session = \Config\Services::session();
            $checkuservars = $session->get();
            $userid = $checkuservars['userid'] ?? null;

            if (!$userid) {
                return json_error('User not authenticated', 401);
            }

            $chkOldPass = $this->profileModel->checkTheOldPassword($userid, $oldpass);
            
            if ($chkOldPass == true) {
                $result = $this->profileModel->updateThePassword($userid, $newpass);
                if ($result) {
                    return json_success(null, 'Password has been successfully updated');
                } else {
                    return json_error('Failed to update password', 500);
                }
            } else {
                return json_error('Old password does not match', 400);
            }
        } else {
            // GET request - return form data or status
            return json_success(null, 'Change password endpoint. Send POST with oldpass, newpass, and retypenewpass');
        }
    }
}
