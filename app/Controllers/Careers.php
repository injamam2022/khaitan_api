<?php

namespace App\Controllers;

use App\Libraries\JobTables;
use App\Models\JobApplicationModel;
use App\Models\JobOpeningModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Careers extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        JobTables::ensure();
    }

    /**
     * GET /api/careers/openings
     */
    public function openings(): ResponseInterface
    {
        $model = new JobOpeningModel();
        $rows = $model->where('status', 'ACTIVE')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'DESC')
            ->findAll();

        $jobs = array_map([$this, 'formatOpening'], $rows ?: []);

        return json_success(['jobs' => $jobs]);
    }

    /**
     * POST /api/careers/apply — multipart: job_opening_id, name, email, phone, message?, resume
     */
    public function apply(): ResponseInterface
    {
        if (!$this->request->is('post')) {
            return json_error('Method not allowed', 405);
        }

        $jobId = (int) ($this->request->getPost('job_opening_id') ?? 0);
        $name = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));
        $phone = trim((string) $this->request->getPost('phone'));
        $message = trim((string) $this->request->getPost('message'));

        if ($jobId < 1) {
            return json_error('Please select a job opening.', 422);
        }
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

        $openingModel = new JobOpeningModel();
        $job = $openingModel->where('id', $jobId)
            ->where('status', 'ACTIVE')
            ->where('is_active', 1)
            ->first();
        if (!$job) {
            return json_error('This job opening is no longer available.', 404);
        }

        $resume = $this->request->getFile('resume');
        if ($resume === null || !$resume->isValid() || $resume->getError() !== UPLOAD_ERR_OK) {
            return json_error('Please upload your resume (PDF, DOC, or DOCX).', 422);
        }
        if ($resume->getSize() > 5 * 1024 * 1024) {
            return json_error('Resume must be 5MB or smaller.', 422);
        }

        $ext = strtolower((string) $resume->getClientExtension());
        $allowedExt = ['pdf', 'doc', 'docx'];
        if (!in_array($ext, $allowedExt, true)) {
            return json_error('Resume must be a PDF, DOC, or DOCX file.', 422);
        }

        $dir = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'job_resumes' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return json_error('Unable to store resume. Please try again later.', 500);
        }

        $originalName = mb_substr((string) $resume->getClientName(), 0, 255);
        $safeName = 'resume_' . $jobId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!$resume->move($dir, $safeName)) {
            return json_error('Unable to store resume. Please try again later.', 500);
        }

        $relativePath = 'assets/job_resumes/' . $safeName;
        $ip = (string) ($this->request->getIPAddress() ?? '');
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $appModel = new JobApplicationModel();
        $ok = $appModel->insert([
            'job_opening_id' => $jobId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message === '' ? null : $message,
            'resume_path' => $relativePath,
            'resume_original_name' => $originalName,
            'form_source' => 'Careers — Apply Now',
            'ip_address' => $ip === '' ? null : substr($ip, 0, 45),
            'user_agent' => $ua === '' ? null : $ua,
            'email_sent' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $insertId = $ok ? (int) $appModel->getInsertID() : 0;
        if ($insertId < 1) {
            return json_error('We could not save your application. Please try again.', 500);
        }

        $emailSent = $this->notifyAdmin($insertId, $job, $name, $email, $phone, $message, $dir . $safeName);
        if ($emailSent) {
            $appModel->update($insertId, ['email_sent' => 1]);
        }

        return json_success([
            'id' => $insertId,
            'email_notified' => $emailSent,
        ], 'Application submitted successfully');
    }

    private function formatOpening(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'job_type' => (string) $row['job_type'],
            'location' => (string) $row['location'],
            'level' => (string) $row['level'],
            'years_experience' => (string) $row['years_experience'],
            'description' => $row['description'] ?? null,
        ];
    }

    private function notifyAdmin(
        int $applicationId,
        array $job,
        string $name,
        string $email,
        string $phone,
        string $message,
        string $resumeAbsolutePath
    ): bool {
        $raw = getenv('CAREERS_NOTIFY_EMAILS') ?: ($_ENV['CAREERS_NOTIFY_EMAILS'] ?? '');
        if (!is_string($raw) || trim($raw) === '') {
            $raw = getenv('CONTACT_NOTIFY_EMAILS') ?: ($_ENV['CONTACT_NOTIFY_EMAILS'] ?? '');
        }
        $recipients = [];
        if (is_string($raw) && trim($raw) !== '') {
            $recipients = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if ($recipients === []) {
            $recipients = ['customercare@khaitan.com', 'alauddin.fc@gmail.com'];
        }
        $valid = array_values(array_filter($recipients, static function ($addr) {
            return is_string($addr) && filter_var(trim($addr), FILTER_VALIDATE_EMAIL);
        }));
        if ($valid === []) {
            return false;
        }

        $fromEmail = (string) (getenv('CAREERS_FROM_EMAIL') ?: ($_ENV['CAREERS_FROM_EMAIL'] ?? getenv('CONTACT_FROM_EMAIL') ?: ($_ENV['CONTACT_FROM_EMAIL'] ?? 'customercare@khaitan.com')));
        $fromName = (string) (getenv('CAREERS_FROM_NAME') ?: ($_ENV['CAREERS_FROM_NAME'] ?? 'Khaitan Careers'));

        try {
            $mail = service('email');
            $mail->clear(true);
            $mail->setMailType('text');
            $mail->setFrom($fromEmail, $fromName);
            $mail->setTo(implode(', ', $valid));
            $mail->setReplyTo($email, $name);
            $mail->setSubject('New job application: ' . substr((string) ($job['title'] ?? 'Opening'), 0, 80));
            $mail->setMessage(implode("\n", [
                'A new job application was submitted from the website.',
                '',
                'Application ID: ' . $applicationId,
                'Job: ' . ($job['title'] ?? ''),
                'Type: ' . ($job['job_type'] ?? ''),
                'Location: ' . ($job['location'] ?? ''),
                '',
                'Name: ' . $name,
                'Email: ' . $email,
                'Phone: ' . $phone,
                'Message:',
                ($message !== '' ? $message : '(none)'),
            ]));
            if (is_file($resumeAbsolutePath)) {
                $mail->attach($resumeAbsolutePath);
            }
            return (bool) $mail->send();
        } catch (\Throwable $e) {
            log_message('error', 'Careers::apply email: ' . $e->getMessage());
            return false;
        }
    }
}
