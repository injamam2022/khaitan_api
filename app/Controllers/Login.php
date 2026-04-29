<?php

namespace App\Controllers;

use App\Models\ProfileModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Login: GET = session check, POST = login.
 * Returns JSON. Session cookie sent via Response (no echo/exit).
 */
class Login extends BaseController
{
    /** Set to true in .env (CI_DEBUG_LOGIN=1) to log login flow */
    private static function debug(): bool
    {
        return (bool) env('CI_DEBUG_LOGIN', false);
    }

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        try {
            parent::initController($request, $response, $logger);
        } catch (\Throwable $e) {
            log_message('error', 'Login::initController - ' . $e->getMessage());
        }
    }

    public function index()
    {
        $method = strtoupper($this->request->getMethod());
        if ($method === 'GET') {
            return $this->getSessionCheck();
        }
        if ($method === 'POST') {
            return $this->postLoginResponse();
        }
        return $this->response->setJSON(['success' => false, 'message' => 'Method not allowed', 'data' => null])->setStatusCode(405);
    }

    private function getSessionCheck()
    {
        $payload = ['success' => false, 'message' => 'Not logged in', 'data' => null];
        try {
            $session = \Config\Services::session();
            $data = $session->get();
            if (!empty($data['is_logged_in']) && (int) $data['is_logged_in'] === 1) {
                $session->set('last_activity', time());
                $payload = [
                    'success' => true,
                    'message' => 'User is logged in',
                    'data' => [
                        'user' => [
                            'id' => $data['userid'] ?? null,
                            'fullname' => $data['fullname'] ?? null,
                            'username' => $data['username'] ?? null,
                            'usertype' => $data['usertype'] ?? null,
                        ],
                    ],
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Login GET - ' . $e->getMessage());
        }
        return $this->response->setJSON($payload)->setStatusCode(200);
    }

    private function postLoginResponse()
    {
        try {
            $result = $this->postLogin();
            if ($result) {
                $res = $this->response->setJSON([
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'data' => $result['data'] ?? null,
                ])->setStatusCode($result['code'] ?? 200);
                if (self::debug()) {
                    try {
                        $store = $this->response->getCookieStore();
                        $names = [];
                        foreach ($store as $c) {
                            $names[] = $c->getName();
                        }
                        log_message('info', '[Login] Response cookies: ' . (count($names) ? implode(', ', $names) : 'NONE'));
                    } catch (\Throwable $e) {
                        log_message('info', '[Login] Response cookies: (check failed)');
                    }
                }
                return $res;
            }
            return $this->response->setJSON(['success' => false, 'message' => 'Login returned empty result', 'data' => null])->setStatusCode(500);
        } catch (\Throwable $e) {
            try {
                log_message('error', 'Login POST - ' . $e->getMessage());
            } catch (\Throwable $logEx) {
                // Do not let logging failure prevent sending response
            }
            $msg = (ENVIRONMENT !== 'production') ? $e->getMessage() : 'Internal server error';
            return $this->response->setJSON(['success' => false, 'message' => $msg, 'data' => null])->setStatusCode(500);
        }
    }

    public function Logout()
    {
        $this->response->setContentType('application/json');
        try {
            \Config\Services::session()->destroy();
            return $this->send(200, true, 'Logged out successfully');
        } catch (\Throwable $e) {
            log_message('error', 'Login::Logout - ' . $e->getMessage());
            return $this->send(500, false, 'Logout failed');
        }
    }

    private function postLogin()
    {
        $username = '';
        $password = '';
        try {
            $json = $this->request->getJSON(true);
            if (is_array($json)) {
                $username = trim((string) ($json['username'] ?? $json['email'] ?? ''));
                $password = (string) ($json['password'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        if ($username === '' || $password === '') {
            $raw = @file_get_contents('php://input');
            if ($raw && is_string($raw)) {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $username = trim((string) ($json['username'] ?? $json['email'] ?? ''));
                    $password = (string) ($json['password'] ?? '');
                }
            }
        }
        if ($username === '' || $password === '') {
            $username = trim((string) ($_POST['username'] ?? $_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
        }

        if ($username === '' || $password === '') {
            return ['code' => 400, 'success' => false, 'message' => 'Username and password are required'];
        }
        if (strlen($username) > 255) {
            return ['code' => 400, 'success' => false, 'message' => 'Invalid request'];
        }

        try {
            $model = new ProfileModel();
            $user = $model->validateLogin($username, $password);
            if (!$user || !is_object($user)) {
                if (self::debug()) {
                    log_message('info', '[Login] 401 for user=' . substr($username, 0, 50));
                }
                return ['code' => 401, 'success' => false, 'message' => 'Invalid username or password'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Login DB - ' . $e->getMessage());
            return ['code' => 500, 'success' => false, 'message' => 'Database error'];
        }

        try {
            $session = \Config\Services::session();
            $session->regenerate(true);
            $usertype = $user->usertype ?? $user->user_type ?? 'ADMIN';
            $session->set([
                'userid' => $user->id,
                'id' => $user->id,
                'fullname' => $user->fullname ?? '',
                'username' => $user->username,
                'usertype' => $usertype,
                'is_logged_in' => 1,
                'last_activity' => time(),
            ]);
            $this->forceSessionCookie($session);
            return [
                'code' => 200,
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => (int) $user->id,
                        'fullname' => $user->fullname ?? '',
                        'username' => $user->username,
                        'usertype' => $usertype,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Login session - ' . $e->getMessage());
            return ['code' => 500, 'success' => false, 'message' => 'Session error'];
        }
    }

    private function forceSessionCookie($session): void
    {
        try {
            $ref = new \ReflectionMethod($session, 'setCookie');
            $ref->setAccessible(true);
            $ref->invoke($session);
        } catch (\Throwable $e) {
            log_message('error', 'Login forceSessionCookie - ' . $e->getMessage());
        }
    }

    private function send(int $code, bool $success, string $message, $data = null)
    {
        $payload = ['success' => $success, 'message' => $message, 'data' => $data];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"success":false,"message":"Error","data":null}';
        $this->response->setStatusCode($code);
        $this->response->setBody($body);
        return $this->response;
    }
}
