<?php

namespace App\Controllers;

use App\Models\ContactEnquiryModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin: list storefront contact enquiries.
 */
class ContactEnquiries extends BaseController
{
    protected ContactEnquiryModel $contactEnquiryModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->contactEnquiryModel = new ContactEnquiryModel();
        helper(['api_helper']);
    }

    /**
     * GET /contact-enquiries?page=1&limit=50
     */
    public function index(): ResponseInterface
    {
        check_auth();

        $limit = (int) $this->request->getGet('limit');
        if ($limit < 1) {
            $limit = 50;
        }
        $limit = min(200, $limit);

        $total = (int) $this->contactEnquiryModel->countAll();
        $totalPages = (int) max(1, (int) ceil($total / $limit));

        $page = max(1, (int) $this->request->getGet('page'));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $limit;
        $rows = $this->contactEnquiryModel
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);

        return json_success([
            'enquiries' => $rows,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int) $total,
                    'total_pages' => $totalPages,
                ],
        ]);
    }

    /**
     * GET /contact-enquiries/export — CSV download (max 10,000 newest rows).
     */
    public function export(): ResponseInterface
    {
        check_auth();

        $maxRows = 10000;
        $rows = $this->contactEnquiryModel
            ->orderBy('id', 'DESC')
            ->findAll($maxRows);

        $headers = [
            'id',
            'created_at',
            'form_source',
            'name',
            'email',
            'phone',
            'message',
            'address',
            'country',
            'state',
            'city',
            'pin',
            'email_sent',
            'ip_address',
            'user_agent',
        ];

        $fh = fopen('php://memory', 'r+b');
        if ($fh === false) {
            return json_error('Could not prepare export.', 500);
        }

        fputcsv($fh, $headers);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['id'] ?? '',
                $r['created_at'] ?? '',
                $r['form_source'] ?? '',
                $r['name'] ?? '',
                $r['email'] ?? '',
                $r['phone'] ?? '',
                $r['message'] ?? '',
                $r['address'] ?? '',
                $r['country'] ?? '',
                $r['state'] ?? '',
                $r['city'] ?? '',
                $r['pin'] ?? '',
                $r['email_sent'] ?? '',
                $r['ip_address'] ?? '',
                $r['user_agent'] ?? '',
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        $filename = 'contact-enquiries-' . date('Y-m-d-His') . '.csv';
        $body = "\xEF\xBB\xBF" . $csv;

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($body);
    }

    /**
     * POST /contact-enquiries/delete — JSON body: { "ids": [1,2,3] } (admin only).
     */
    public function bulkDelete(): ResponseInterface
    {
        check_auth();

        if (!$this->request->is('post')) {
            return json_error('Method not allowed', 405);
        }

        $this->response->setContentType('application/json');

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $ids = $payload['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return json_error('Provide a non-empty ids array.', 422);
        }

        $clean = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $clean[$n] = true;
            }
        }

        $idList = array_keys($clean);

        $maxBulk = 200;
        if (count($idList) > $maxBulk) {
            return json_error('Too many ids (max ' . $maxBulk . ' per request).', 422);
        }

        if ($idList === []) {
            return json_error('No valid ids.', 422);
        }

        $this->contactEnquiryModel->db->table('contact_enquiries')->whereIn('id', $idList)->delete();
        $deleted = (int) $this->contactEnquiryModel->db->affectedRows();

        log_message('notice', 'ContactEnquiries bulkDelete: ids=' . json_encode($idList) . ' affected=' . $deleted);

        return json_success([
            'deleted' => $deleted,
        ], $deleted === 1 ? '1 enquiry deleted.' : $deleted . ' enquiries deleted.');
    }
}
