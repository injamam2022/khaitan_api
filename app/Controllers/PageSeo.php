<?php

namespace App\Controllers;

use App\Libraries\PageSeoTables;
use App\Models\PageSeoModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class PageSeo extends BaseController
{
    protected PageSeoModel $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        PageSeoTables::ensure();
        $this->model = new PageSeoModel();
    }

    public function index(): ResponseInterface
    {
        check_auth();
        return json_success($this->allPages());
    }

    public function publicIndex(): ResponseInterface
    {
        return json_success($this->allPages());
    }

    public function publicShow($slug = null): ResponseInterface
    {
        $row = $this->findPage((string) $slug);
        if (!$row) {
            return json_error('Page SEO not found.', 404);
        }
        return json_success($row);
    }

    public function edit($slug = null): ResponseInterface
    {
        check_auth();

        $slug = strtolower(trim((string) $slug));
        if (!in_array($slug, PageSeoTables::allowedSlugs(), true)) {
            return json_error('Unknown page.', 404);
        }

        $payload = $this->requestPayload();

        $defaults = PageSeoTables::PAGES[$slug];
        $data = [
            'label' => $defaults['label'],
            'meta_title' => $this->clip($payload['meta_title'] ?? '', 255),
            'meta_description' => $this->clip($payload['meta_description'] ?? '', 512),
            'meta_keywords' => $this->clip($payload['meta_keywords'] ?? '', 512),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = $this->model->find($slug);
        if ($existing) {
            $this->model->update($slug, $data);
        } else {
            $this->model->insert(['slug' => $slug] + $data);
        }

        return json_success($this->findPage($slug), 'Page SEO updated');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allPages(): array
    {
        $rows = [];
        foreach (PageSeoTables::allowedSlugs() as $slug) {
            $row = $this->findPage($slug);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPage(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if (!isset(PageSeoTables::PAGES[$slug])) {
            return null;
        }
        $defaults = PageSeoTables::PAGES[$slug];
        $row = $this->model->find($slug);
        return [
            'slug' => $slug,
            'label' => $defaults['label'],
            'path' => $defaults['path'],
            'meta_title' => trim((string) ($row['meta_title'] ?? '')) ?: $defaults['meta_title'],
            'meta_description' => trim((string) ($row['meta_description'] ?? '')) ?: $defaults['meta_description'],
            'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')) ?: $defaults['meta_keywords'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        $raw = trim((string) $this->request->getBody());
        if ($raw !== '') {
            try {
                $decoded = $this->request->getJSON(true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable $e) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        $post = $this->request->getPost();
        return is_array($post) ? $post : [];
    }

    private function clip($value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $max);
    }
}
