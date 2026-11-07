<?php

namespace App\Controllers;

use App\Libraries\BlogTables;
use App\Models\BlogPostModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class BlogPosts extends BaseController
{
    protected BlogPostModel $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        BlogTables::ensure();
        $this->model = new BlogPostModel();
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

        $rows = $builder->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();

        return json_success([
            'posts' => array_map([$this, 'formatRow'], $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function publicIndex(): ResponseInterface
    {
        if ($this->isOptions()) {
            return $this->response->setStatusCode(204);
        }

        $limit = min(100, max(1, (int) $this->request->getGet('limit') ?: 50));
        $rows = $this->model->builder()
            ->where('status', 'ACTIVE')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return json_success([
            'posts' => array_map([$this, 'formatRow'], $rows),
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

        $slug = $this->cleanSlug((string) ($payload['slug'] ?? $row['title']));
        if ($slug === '') {
            return json_error('Please provide a valid slug.', 422);
        }
        if ($this->slugExists($slug)) {
            return json_error('Choose a unique blog URL slug.', 422);
        }

        $image = $this->storeImage();
        if ($image instanceof ResponseInterface) {
            return $image;
        }
        if ($image !== null) {
            $row['image'] = $image;
        }

        $now = date('Y-m-d H:i:s');
        $row['slug'] = $slug;
        $row['status'] = 'ACTIVE';
        $row['published_at'] = $row['is_active'] ? $now : null;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        if (empty($row['link'])) {
            $row['link'] = $this->defaultLink($row['category'], $slug);
        }

        if (!$this->model->insert($row)) {
            return json_error('Unable to create blog post.', 500);
        }

        return json_success(['id' => (int) $this->model->getInsertID()], 'Blog post created');
    }

    public function edit($id = null): ResponseInterface
    {
        check_auth();
        $id = (int) $id;
        $existing = $this->model->where('id', $id)->where('status <>', 'DELETED')->first();
        if (!$existing) {
            return json_error('Blog post not found.', 404);
        }

        $payload = $this->readPayload();
        $row = $this->validatePayload($payload, false);
        if ($row instanceof ResponseInterface) {
            return $row;
        }

        if (array_key_exists('slug', $payload)) {
            $slug = $this->cleanSlug((string) $payload['slug']);
            if ($slug === '' || $this->slugExists($slug, $id)) {
                return json_error('Choose a unique blog URL slug.', 422);
            }
            $row['slug'] = $slug;
        } elseif (isset($row['title']) && strcasecmp((string) $existing['title'], $row['title']) !== 0) {
            $row['slug'] = $this->uniqueSlug($row['title'], $id);
        }

        $image = $this->storeImage();
        if ($image instanceof ResponseInterface) {
            return $image;
        }
        if ($image !== null) {
            $row['image'] = $image;
        }

        if (isset($row['is_active']) && (int) $row['is_active'] === 1 && empty($existing['published_at'])) {
            $row['published_at'] = date('Y-m-d H:i:s');
        }

        $row['updated_at'] = date('Y-m-d H:i:s');
        $this->model->update($id, $row);
        return json_success(['id' => $id], 'Blog post updated');
    }

    public function delete($id = null): ResponseInterface
    {
        check_auth();
        $id = (int) $id;
        $existing = $this->model->where('id', $id)->where('status <>', 'DELETED')->first();
        if (!$existing) {
            return json_error('Blog post not found.', 404);
        }
        $this->model->update($id, [
            'status' => 'DELETED',
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json_success(['id' => $id], 'Blog post deleted');
    }

    private function readPayload(): array
    {
        $json = $this->request->getJSON(true);
        if (is_array($json)) {
            return $json;
        }
        return $this->request->getPost() ?: [];
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validatePayload(array $payload, bool $requireTitle = true)
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($requireTitle && ($title === '' || mb_strlen($title) > 255)) {
            return json_error('Please provide a valid blog title.', 422);
        }
        if (!$requireTitle && $title === '' && array_key_exists('title', $payload)) {
            return json_error('Please provide a valid blog title.', 422);
        }

        $rawActive = $payload['is_active'] ?? 1;
        $isActive = ($rawActive === 0 || $rawActive === '0' || $rawActive === false || $rawActive === 'false') ? 0 : 1;

        $row = [
            'category' => mb_substr(trim((string) ($payload['category'] ?? '')), 0, 120),
            'subcategory' => mb_substr(trim((string) ($payload['subcategory'] ?? '')), 0, 120),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'link' => mb_substr(trim((string) ($payload['link'] ?? '')), 0, 500) ?: null,
            'content' => array_key_exists('content', $payload)
                ? (trim((string) $payload['content']) ?: null)
                : null,
            'seo_title' => mb_substr(trim((string) ($payload['seo_title'] ?? '')), 0, 180) ?: null,
            'seo_description' => mb_substr(trim((string) ($payload['seo_description'] ?? '')), 0, 320) ?: null,
            'is_active' => $isActive,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ];

        if ($title !== '') {
            $row['title'] = mb_substr($title, 0, 255);
        }

        if (array_key_exists('image', $payload) && is_string($payload['image'])) {
            $image = trim($payload['image']);
            $row['image'] = $image === '' ? null : mb_substr($image, 0, 500);
        }

        if (!$requireTitle && !array_key_exists('content', $payload)) {
            unset($row['content']);
        }

        return $row;
    }

    /**
     * @return string|null|ResponseInterface
     */
    private function storeImage()
    {
        $file = $this->request->getFile('image');
        if (!$file || !$file->isValid() || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $mime = (string) $file->getMimeType();
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowed, true)) {
            return json_error('Upload a JPG, PNG, WEBP, or GIF image.', 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return json_error('Image must be 5MB or smaller.', 422);
        }

        $dir = FCPATH . 'assets/blogs';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return json_error('Could not create blog image folder.', 500);
        }

        $ext = strtolower($file->getExtension() ?: 'jpg');
        $name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!$file->move($dir, $name, true)) {
            return json_error('Could not save blog image.', 500);
        }

        return 'assets/blogs/' . $name;
    }

    private function formatRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['sort_order'] = (int) $row['sort_order'];
        if (!empty($row['image']) && is_string($row['image']) && !preg_match('/^https?:\/\//', $row['image'])) {
            $path = ltrim($row['image'], '/');
            // Uploaded covers live on the API host; storefront-relative /assets/img paths stay as-is.
            if (str_starts_with($path, 'assets/blogs/')) {
                $row['image'] = base_url($path);
            } elseif (!str_starts_with($row['image'], '/')) {
                $row['image'] = '/' . $path;
            }
        }
        return $row;
    }

    private function cleanSlug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-'));
        return mb_substr($slug, 0, 180);
    }

    private function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->model->builder()->where('slug', $slug)->where('status <>', 'DELETED');
        if ($excludeId) {
            $builder->where('id <>', $excludeId);
        }
        return (int) $builder->countAllResults() > 0;
    }

    private function uniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = $this->cleanSlug($title) ?: 'blog';
        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug, $excludeId)) {
            $slug = mb_substr($base . '-' . $i, 0, 180);
            $i++;
        }
        return $slug;
    }

    private function defaultLink(string $category, string $slug): string
    {
        $guide = $this->cleanSlug($category);
        if ($guide === '') {
            $guide = 'guides';
        }
        if (!str_ends_with($guide, '-guide') && !str_ends_with($guide, '-guides')) {
            $guide .= '-guide';
        }
        return '/blogs/' . $guide . '/' . $slug;
    }

    private function isOptions(): bool
    {
        return strtoupper($this->request->getMethod()) === 'OPTIONS';
    }
}
