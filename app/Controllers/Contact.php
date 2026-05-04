<?php

namespace App\Controllers;

use App\Models\ContactEnquiryModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Public storefront contact form (replaces legacy form-api/contact-api.php flows).
 */
class Contact extends BaseController
{
    /** Default inbox when CONTACT_NOTIFY_EMAILS is not set in .env */
    private static function notificationRecipients(): array
    {
        $raw = getenv('CONTACT_NOTIFY_EMAILS')
            ?: ($_ENV['CONTACT_NOTIFY_EMAILS'] ?? '');
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return [
            'customercare@khaitan.com',
            'alauddin.fc@gmail.com',
        ];
    }

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * POST /api/contact/submit — JSON body: name, phone, email, message?, address?, country?, state?, city?, pin?, form_source?
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
            $formSourceRaw = isset($payload['form_source']) ? trim((string) $payload['form_source']) : '';
            $formSource = $formSourceRaw === '' ? null : mb_substr($formSourceRaw, 0, 120);

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

            $ip = (string) ($this->request->getIPAddress() ?? '');
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
            $now = date('Y-m-d H:i:s');

            $meta = sprintf(
                "form=%s | name=%s | email=%s | phone=%s | message=%s | address=%s | country=%s | state=%s | city=%s | pin=%s | ip=%s | ua=%s",
                substr((string) ($formSource ?? ''), 0, 120),
                $name,
                $email,
                $phone,
                substr($message, 0, 2000),
                substr($address, 0, 500),
                substr($country, 0, 100),
                substr($state, 0, 100),
                substr($city, 0, 100),
                substr($pin, 0, 20),
                $ip,
                $ua
            );
            log_message('notice', 'Contact form submission: ' . $meta);

            $line = date('c') . ' | ' . $meta . PHP_EOL;
            $logDir = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR;
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logDir . 'contact_submissions.log', $line, FILE_APPEND | LOCK_EX);
            }

            $insertId = null;
            try {
                $model = new ContactEnquiryModel();
                $ok = $model->insert([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'message' => $message === '' ? null : $message,
                    'address' => $address === '' ? null : $address,
                    'country' => $country === '' ? null : $country,
                    'state' => $state === '' ? null : $state,
                    'city' => $city === '' ? null : $city,
                    'pin' => $pin === '' ? null : $pin,
                    'form_source' => $formSource,
                    'ip_address' => $ip === '' ? null : substr($ip, 0, 45),
                    'user_agent' => $ua === '' ? null : $ua,
                    'email_sent' => 0,
                    'created_at' => $now,
                ]);
                if ($ok) {
                    $insertId = (int) $model->getInsertID();
                }
                if (!$ok || $insertId < 1) {
                    $insertId = null;
                    log_message('error', 'Contact::submit — insert failed');
                }
            } catch (DatabaseException $e) {
                log_message('error', 'Contact::submit DB: ' . $e->getMessage());

                return json_error(
                    'We could not save your enquiry. Ensure the database is updated (run migrations), then try again.',
                    500
                );
            }

            $emailSent = false;
            $recipients = self::notificationRecipients();
            $validRecipients = array_values(array_filter($recipients, static function ($addr) {
                return is_string($addr) && filter_var(trim($addr), FILTER_VALIDATE_EMAIL);
            }));

            if ($insertId !== null && count($validRecipients) > 0) {
                $fromEmail = (string) (getenv('CONTACT_FROM_EMAIL') ?: ($_ENV['CONTACT_FROM_EMAIL'] ?? 'customercare@khaitan.com'));
                $fromName = (string) (getenv('CONTACT_FROM_NAME') ?: ($_ENV['CONTACT_FROM_NAME'] ?? 'Khaitan Website'));

                try {
                    $mail = service('email');
                    $mail->clear();
                    $mail->setMailType('text');
                    $mail->setFrom($fromEmail, $fromName);
                    $mail->setTo(implode(', ', $validRecipients));
                    $mail->setSubject('New website enquiry: ' . substr($name, 0, 80));
                    $body = $this->buildNotificationBody($name, $email, $phone, $message, $address, $country, $state, $city, $pin, $ip, $insertId, $formSource);
                    $mail->setMessage($body);
                    if ($mail->send()) {
                        $emailSent = true;
                        $model = new ContactEnquiryModel();
                        $model->update((int) $insertId, ['email_sent' => 1]);
                    } else {
                        log_message('error', 'Contact::submit email send failed — ' . $mail->printDebugger([], true));
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'Contact::submit email exception: ' . $e->getMessage());
                }
            }

            return json_success([
                'received' => true,
                'id' => $insertId !== null ? (int) $insertId : null,
                'email_notified' => $emailSent,
            ], 'Form submitted successfully');
        } catch (\Throwable $e) {
            log_message('error', 'Contact::submit: ' . $e->getMessage());

            return json_error('Unable to submit form. Please try again later.', 500);
        }
    }

    private function buildNotificationBody(
        string $name,
        string $email,
        string $phone,
        string $message,
        string $address,
        string $country,
        string $state,
        string $city,
        string $pin,
        string $ip,
        $recordId,
        ?string $formSource = null,
    ): string {
        $lines = [
            'A new enquiry was submitted from the website.',
            '',
            'ID: ' . (string) $recordId,
            'Form: ' . ($formSource !== null && $formSource !== '' ? $formSource : '(not specified)'),
            'Name: ' . $name,
            'Email: ' . $email,
            'Phone: ' . $phone,
            'Message:',
            ($message !== '' ? $message : '(none)'),
        ];
        if ($address !== '' || $country !== '' || $state !== '' || $city !== '' || $pin !== '') {
            $lines[] = '';
            $lines[] = 'Address details:';
            if ($address !== '') {
                $lines[] = '  Address: ' . $address;
            }
            if ($country !== '') {
                $lines[] = '  Country: ' . $country;
            }
            if ($state !== '') {
                $lines[] = '  State: ' . $state;
            }
            if ($city !== '') {
                $lines[] = '  City: ' . $city;
            }
            if ($pin !== '') {
                $lines[] = '  Pin: ' . $pin;
            }
        }
        if ($ip !== '') {
            $lines[] = '';
            $lines[] = 'Submitter IP: ' . $ip;
        }

        return implode("\n", $lines);
    }
}
