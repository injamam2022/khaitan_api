<?php

namespace App\Controllers;

use App\Libraries\JobTables;
use App\Models\JobOpeningModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class JobOpenings extends BaseController
{
    protected JobOpeningModel $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        JobTables::ensure();
        $this->model = new JobOpeningModel();
    }

    public function index(): ResponseInterface
    {
        check_auth();

        $limit = min(200, max(1, (int) $this->request->getGet('limit') ?: 50));
        $builder = $this->model->builder()->where('status <>', 'DELETED');
        $total = (int) $builder->countAllResults(false);
        $totalPages = (int) max(1, (int) ceil($total / $limit));
        $page = max(1, (int) $this->request->getGet('page'));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $builder->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();

        return json_success([
            'jobs' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function add(): ResponseInterface
    {
        check_auth();
        $payload = $this->readPayload();
        $row = $this->validatePayload($payload);
        if ($row instanceof ResponseInterface) {
            return $row;
        }
        $row['slug'] = $this->uniqueSlug($row['title']);
        $row['status'] = 'ACTIVE';
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = $row['created_at'];
        if (!$this->model->insert($row)) {
            return json_error('Unable to create job opening.', 500);
        }
        return json_success(['id' => (int) $this->model->getInsertID()], 'Job opening created');
    }

    public function edit($id = null): ResponseInterface
    {
        check_auth();
        $id = (int) $id;
        $existing = $this->model->where('id', $id)->where('status <>', 'DELETED')->first();
        if (!$existing) {
            return json_error('Job opening not found.', 404);
        }
        $payload = $this->readPayload();
        $row = $this->validatePayload($payload);
        if ($row instanceof ResponseInterface) {
            return $row;
        }
        if (strcasecmp((string) $existing['title'], $row['title']) !== 0) {
            $row['slug'] = $this->uniqueSlug($row['title'], $id);
        }
        $row['updated_at'] = date('Y-m-d H:i:s');
        $this->model->update($id, $row);
        return json_success(['id' => $id], 'Job opening updated');
    }

    public function delete($id = null): ResponseInterface
    {
        check_auth();
        $id = (int) $id;
        $existing = $this->model->where('id', $id)->where('status <>', 'DELETED')->first();
        if (!$existing) {
            return json_error('Job opening not found.', 404);
        }
        $this->model->update($id, [
            'status' => 'DELETED',
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json_success(['id' => $id], 'Job opening deleted');
    }

    private function readPayload(): array
    {
        $json = $this->request->getJSON(true);
        return is_array($json) ? $json : $this->request->getPost();
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validatePayload(array $payload)
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $jobType = trim((string) ($payload['job_type'] ?? 'Full Time'));
        $location = trim((string) ($payload['location'] ?? ''));
        $level = trim((string) ($payload['level'] ?? ''));
        $exp = trim((string) ($payload['years_experience'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $rawActive = $payload['is_active'] ?? 1;
        $isActive = ($rawActive === 0 || $rawActive === '0' || $rawActive === false || $rawActive === 'false') ? 0 : 1;
        $sortOrder = (int) ($payload['sort_order'] ?? 0);

        if ($title === '' || mb_strlen($title) > 200) {
            return json_error('Please provide a valid job title.', 422);
        }
        if ($jobType === '' || mb_strlen($jobType) > 50) {
            return json_error('Please provide a valid job type.', 422);
        }
        if ($location === '' || mb_strlen($location) > 100) {
            return json_error('Please provide a valid location.', 422);
        }

        return [
            'title' => $title,
            'job_type' => $jobType,
            'location' => $location,
            'level' => mb_substr($level, 0, 50),
            'years_experience' => mb_substr($exp, 0, 20),
            'description' => $description === '' ? null : $description,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ];
    }

    private function uniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
        if ($base === '') {
            $base = 'job';
        }
        $slug = $base;
        $i = 2;
        while (true) {
            $builder = $this->model->builder()->where('slug', $slug)->where('status <>', 'DELETED');
            if ($excludeId) {
                $builder->where('id <>', $excludeId);
            }
            if ((int) $builder->countAllResults() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}
