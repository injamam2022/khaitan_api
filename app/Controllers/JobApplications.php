<?php

namespace App\Controllers;

use App\Libraries\JobTables;
use App\Models\JobApplicationModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class JobApplications extends BaseController
{
    protected JobApplicationModel $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        JobTables::ensure();
        $this->model = new JobApplicationModel();
    }

    public function index(): ResponseInterface
    {
        check_auth();

        $limit = min(200, max(1, (int) $this->request->getGet('limit') ?: 50));
        $total = (int) $this->model->builder()->countAllResults();
        $totalPages = (int) max(1, (int) ceil($total / $limit));
        $page = max(1, (int) $this->request->getGet('page'));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $this->model->db->table('apply_job_tables AS A')
            ->select('A.*, J.title AS job_title, J.location AS job_location, J.job_type AS job_type')
            ->join('create_job_tables AS J', 'J.id = A.job_opening_id', 'left')
            ->orderBy('A.id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();

        return json_success([
            'applications' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function bulkDelete(): ResponseInterface
    {
        check_auth();
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $ids = array_values(array_filter(array_map('intval', $payload['ids'] ?? [])));
        $ids = array_slice($ids, 0, 200);
        if ($ids === []) {
            return json_error('No applications selected.', 422);
        }
        $this->model->whereIn('id', $ids)->delete();
        return json_success(['deleted' => count($ids)], 'Applications deleted');
    }

    public function resume($id = null): ResponseInterface
    {
        check_auth();
        $row = $this->model->find((int) $id);
        if (!$row || empty($row['resume_path'])) {
            return json_error('Resume not found.', 404);
        }

        $absolute = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim((string) $row['resume_path'], '/\\'));
        if (!is_file($absolute)) {
            return json_error('Resume file is missing.', 404);
        }

        $downloadName = (string) ($row['resume_original_name'] ?: basename($absolute));
        return $this->response->download($absolute, null)->setFileName($downloadName);
    }
}
