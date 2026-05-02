<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Public storefront contact form (replaces legacy form-api/contact-api.php flows).
 */
class Contact extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * POST /api/contact/submit — JSON body: name, phone, email, message?, address?, country?, state?, city?, pin?
     */
    public function submit(): ResponseInterface
    {
        $this->response->setContentType('application/json');

        if (!$this->request->is('post')) {
            return json_error('Method not allowed', 405);
        }

        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
            $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
            $phone = isset($payload['phone']) ? trim((string) $payload['phone']) : '';
            $message = isset($payload['message']) ? trim((string) $payload['message']) : '';
            $address = isset($payload['address']) ? trim((string) $payload['address']) : '';
            $country = isset($payload['country']) ? trim((string) $payload['country']) : '';
            $state = isset($payload['state']) ? trim((string) $payload['state']) : '';
            $city = isset($payload['city']) ? trim((string) $payload['city']) : '';
            $pin = isset($payload['pin']) ? trim((string) $payload['pin']) : '';

            if ($name === '' || mb_strlen($name) > 200) {
                return json_error('Please provide a valid name.', 422);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return json_error('Please provide a valid email address.', 422);
            }
            if ($phone === '' || mb_strlen($phone) > 40) {
                return json_error('Please provide a valid phone number.', 422);
            }
            if (mb_strlen($message) > 4000) {
                return json_error('Message is too long.', 422);
            }

            $meta = sprintf(
                "name=%s | email=%s | phone=%s | message=%s | address=%s | country=%s | state=%s | city=%s | pin=%s | ip=%s | ua=%s",
                $name,
                $email,
                $phone,
                substr($message, 0, 2000),
                substr($address, 0, 500),
                substr($country, 0, 100),
                substr($state, 0, 100),
                substr($city, 0, 100),
                substr($pin, 0, 20),
                (string) ($this->request->getIPAddress() ?? ''),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)
            );
            log_message('notice', 'Contact form submission: ' . $meta);

            $line = date('c') . ' | ' . $meta . PHP_EOL;
            $logDir = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR;
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logDir . 'contact_submissions.log', $line, FILE_APPEND | LOCK_EX);
            }

            return json_success(['received' => true], 'Form submitted successfully');
        } catch (\Throwable $e) {
            log_message('error', 'Contact::submit: ' . $e->getMessage());

            return json_error('Unable to submit form. Please try again later.', 500);
        }
    }
}
