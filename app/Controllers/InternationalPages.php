<?php

namespace App\Controllers;

use App\Models\ContactEnquiryModel;
use App\Models\InternationalPageModel;
use CodeIgniter\HTTP\ResponseInterface;

class InternationalPages extends BaseController
{
    protected InternationalPageModel $pages;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
        $this->pages = new InternationalPageModel();
        $this->pages->ensureSchema();
    }

    public function index(): ResponseInterface
    {
        check_auth();
        return json_success($this->pages->listPages(false));
    }

    public function show($id = null): ResponseInterface
    {
        check_auth();
        $page = $this->pages->getPage((int) $id, true);
        if (!$page) {
            return json_error('Page not found', 404);
        }
        return json_success($page);
    }

    public function add(): ResponseInterface
    {
        check_auth();
        $payload = $this->readPayload();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return json_error('Enter a location name.', 422);
        }
        $slug = $this->cleanSlug((string) ($payload['slug'] ?? $name));
        if ($slug === '' || $this->pages->slugExists($slug)) {
            return json_error('Choose a unique page address.', 422);
        }
        $id = $this->pages->createPage([
            'name' => $name,
            'slug' => $slug,
            'country' => trim((string) ($payload['country'] ?? $name)),
            'status' => 'draft',
            'interest_options' => $this->pages->defaultInterests(),
        ]);
        $this->pages->addFold($id, 'hero');
        return json_success($this->pages->getPage($id, true), 'Location created');
    }

    public function edit($id = null): ResponseInterface
    {
        check_auth();
        $pageId = (int) $id;
        if (!$this->pages->getPage($pageId, false)) {
            return json_error('Page not found', 404);
        }
        $payload = $this->readPayload();
        $update = [];
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return json_error('Location name is required.', 422);
            }
            $update['name'] = mb_substr($name, 0, 120);
        }
        if (array_key_exists('slug', $payload)) {
            $slug = $this->cleanSlug((string) $payload['slug']);
            if ($slug === '' || $this->pages->slugExists($slug, $pageId)) {
                return json_error('Choose a unique page address.', 422);
            }
            $update['slug'] = $slug;
        }
        foreach (['country', 'status', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = trim((string) $payload[$field]);
            }
        }
        if (isset($update['status']) && !in_array($update['status'], ['draft', 'published'], true)) {
            return json_error('Status must be draft or published.', 422);
        }
        if (array_key_exists('interest_options', $payload) && is_array($payload['interest_options'])) {
            $update['interest_options'] = array_values(array_filter(array_map(
                static fn ($item) => trim((string) $item),
                $payload['interest_options']
            )));
        }
        $this->pages->updatePage($pageId, $update);
        return json_success($this->pages->getPage($pageId, true), 'Page saved');
    }

    public function delete($id = null): ResponseInterface
    {
        check_auth();
        $pageId = (int) $id;
        if (!$this->pages->getPage($pageId, false)) {
            return json_error('Page not found', 404);
        }
        $this->pages->deletePage($pageId);
        return json_success(null, 'Page deleted');
    }

    public function duplicate($id = null): ResponseInterface
    {
        check_auth();
        $newId = $this->pages->duplicatePage((int) $id);
        if (!$newId) {
            return json_error('Page not found', 404);
        }
        return json_success($this->pages->getPage($newId, true), 'Page duplicated');
    }

    public function sample(): ResponseInterface
    {
        check_auth();
        $existing = $this->pages->findBySlug('uae', false);
        if ($existing) {
            return json_success($existing, 'UAE sample is already in the list');
        }
        $this->pages->seedSamples();
        $page = $this->pages->findBySlug('uae', false);
        return json_success($page, 'Sample locations created');
    }

    public function addFold($pageId = null): ResponseInterface
    {
        check_auth();
        $id = (int) $pageId;
        if (!$this->pages->getPage($id, false)) {
            return json_error('Page not found', 404);
        }
        $payload = $this->readPayload();
        $type = $this->cleanType((string) ($payload['fold_type'] ?? 'showcase'));
        $foldId = $this->pages->addFold($id, $type);
        return json_success($this->pages->getFold($foldId), 'Fold added');
    }

    public function editFold($id = null): ResponseInterface
    {
        check_auth();
        $foldId = (int) $id;
        $fold = $this->pages->getFold($foldId);
        if (!$fold) {
            return json_error('Fold not found', 404);
        }
        $payload = $this->readPayload();
        $update = [];
        if (array_key_exists('fold_type', $payload)) {
            $update['fold_type'] = $this->cleanType((string) $payload['fold_type']);
        }
        foreach (['title', 'body', 'trust_line', 'cta_primary_label', 'cta_primary_url', 'cta_secondary_label', 'brochure_label'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = trim((string) $payload[$field]);
            }
        }
        if (array_key_exists('is_active', $payload)) {
            $update['is_active'] = !empty($payload['is_active']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $payload)) {
            $update['sort_order'] = (int) $payload['sort_order'];
        }
        if (array_key_exists('payload', $payload) && is_array($payload['payload'])) {
            $update['payload'] = $payload['payload'];
        }
        $this->pages->updateFold($foldId, $update);
        return json_success($this->pages->getFold($foldId), 'Fold saved');
    }

    public function deleteFold($id = null): ResponseInterface
    {
        check_auth();
        $fold = $this->pages->getFold((int) $id);
        if (!$fold) {
            return json_error('Fold not found', 404);
        }
        $this->pages->deleteFold((int) $id);
        return json_success(null, 'Fold deleted');
    }

    public function brochure($id = null): ResponseInterface
    {
        check_auth();
        $fold = $this->pages->getFold((int) $id);
        if (!$fold) {
            return json_error('Fold not found', 404);
        }
        $file = $this->request->getFile('brochure');
        if (!$file || !$file->isValid()) {
            return json_error('Choose a brochure file.', 422);
        }
        $ext = strtolower((string) $file->getExtension());
        if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) {
            return json_error('Upload a PDF or Word brochure.', 422);
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            return json_error('Brochure must be under 20 MB.', 422);
        }
        $uploadPath = FCPATH . 'assets/intl-brochures/';
        if (!is_dir($uploadPath) && !mkdir($uploadPath, 0755, true) && !is_dir($uploadPath)) {
            return json_error('Could not store the brochure.', 500);
        }
        $newName = 'fold-' . (int) $id . '-' . time() . '.' . $ext;
        if (!$file->move($uploadPath, $newName)) {
            return json_error('Could not store the brochure.', 500);
        }
        $relative = 'assets/intl-brochures/' . $newName;
        $this->pages->updateFold((int) $id, [
            'brochure_url' => $relative,
            'brochure_name' => $file->getClientName(),
        ]);
        return json_success($this->pages->getFold((int) $id), 'Brochure uploaded');
    }

    public function banner($id = null): ResponseInterface
    {
        check_auth();
        $fold = $this->pages->getFold((int) $id);
        if (!$fold) {
            return json_error('Fold not found', 404);
        }
        $file = $this->request->getFile('banner');
        if (!$file || !$file->isValid()) {
            return json_error('Choose a banner image.', 422);
        }
        $ext = strtolower((string) $file->getExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return json_error('Upload a JPG, PNG, WEBP or GIF banner.', 422);
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return json_error('Banner must be under 8 MB.', 422);
        }
        $uploadPath = FCPATH . 'assets/intl-banners/';
        if (!is_dir($uploadPath) && !mkdir($uploadPath, 0755, true) && !is_dir($uploadPath)) {
            return json_error('Could not store the banner.', 500);
        }
        $newName = 'fold-' . (int) $id . '-' . time() . '.' . $ext;
        if (!$file->move($uploadPath, $newName)) {
            return json_error('Could not store the banner.', 500);
        }
        $payload = is_array($fold['payload']) ? $fold['payload'] : [];
        $payload['hero_image'] = 'assets/intl-banners/' . $newName;
        $this->pages->updateFold((int) $id, ['payload' => $payload]);
        return json_success($this->pages->getFold((int) $id), 'Banner updated');
    }

    public function enquiries(): ResponseInterface
    {
        check_auth();
        $pageId = (int) ($this->request->getGet('page_id') ?: 0);
        $limit = min(100, max(1, (int) ($this->request->getGet('limit') ?: 50)));
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));
        $result = $this->pages->listEnquiries($pageId > 0 ? $pageId : null, $limit, ($page - 1) * $limit);
        return json_success([
            'enquiries' => $result['rows'],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'total_pages' => (int) max(1, ceil($result['total'] / $limit)),
            ],
        ]);
    }

    public function publicIndex(): ResponseInterface
    {
        if ($this->isOptions()) {
            return $this->response->setStatusCode(204);
        }
        return json_success($this->pages->listPages(true));
    }

    public function publicShow($slug = null): ResponseInterface
    {
        if ($this->isOptions()) {
            return $this->response->setStatusCode(204);
        }
        $page = $this->pages->findBySlug((string) $slug, true);
        if (!$page) {
            return json_error('Page not found', 404);
        }
        $page['folds'] = array_values(array_filter(
            $page['folds'] ?? [],
            static fn ($fold) => !empty($fold['is_active'])
        ));
        return json_success($page);
    }

    public function enquire(): ResponseInterface
    {
        if ($this->isOptions()) {
            return $this->response->setStatusCode(204);
        }
        if (!$this->request->is('post')) {
            return json_error('Method not allowed', 405);
        }
        $payload = $this->readPayload();
        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $interested = trim((string) ($payload['interested_in'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $country = trim((string) ($payload['country'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $slug = trim((string) ($payload['page_slug'] ?? ''));
        $foldTitle = trim((string) ($payload['fold_title'] ?? ''));

        if ($name === '' || mb_strlen($name) > 200) {
            return json_error('Please provide a name or company.', 422);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_error('Please provide a valid email address.', 422);
        }
        if ($phone === '' || mb_strlen($phone) > 40) {
            return json_error('Please provide a valid phone number.', 422);
        }
        if ($interested === '') {
            return json_error('Please choose what you are interested in.', 422);
        }
        if (mb_strlen($notes) > 4000) {
            return json_error('Notes are too long.', 422);
        }

        $page = $slug !== '' ? $this->pages->findBySlug($slug, true) : null;
        $pageName = $page['name'] ?? trim((string) ($payload['page_name'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $ip = (string) ($this->request->getIPAddress() ?? '');
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $id = $this->pages->saveEnquiry([
            'page_id' => $page['id'] ?? null,
            'page_name' => $pageName !== '' ? mb_substr($pageName, 0, 120) : null,
            'page_slug' => $slug !== '' ? mb_substr($slug, 0, 160) : null,
            'fold_title' => $foldTitle !== '' ? mb_substr($foldTitle, 0, 300) : null,
            'name' => $name,
            'phone' => $phone,
            'interested_in' => mb_substr($interested, 0, 120),
            'email' => $email,
            'address' => $address !== '' ? mb_substr($address, 0, 500) : null,
            'country' => $country !== '' ? mb_substr($country, 0, 120) : null,
            'notes' => $notes !== '' ? $notes : null,
            'ip_address' => $ip !== '' ? $ip : null,
            'user_agent' => $ua !== '' ? $ua : null,
        ]);

        try {
            $source = 'International' . ($pageName !== '' ? ' — ' . $pageName : '');
            $message = "Interested in: {$interested}";
            if ($foldTitle !== '') {
                $message .= "\nSection: {$foldTitle}";
            }
            if ($notes !== '') {
                $message .= "\n\n{$notes}";
            }
            $contact = new ContactEnquiryModel();
            $contact->insert([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'address' => $address !== '' ? mb_substr($address, 0, 500) : null,
                'country' => $country !== '' ? mb_substr($country, 0, 120) : null,
                'form_source' => mb_substr($source, 0, 120),
                'ip_address' => $ip !== '' ? $ip : null,
                'user_agent' => $ua !== '' ? $ua : null,
                'email_sent' => 0,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'International enquiry contact copy failed: ' . $e->getMessage());
        }

        return json_success(['id' => $id], 'Enquiry received');
    }

    private function readPayload(): array
    {
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }
        return is_array($payload) ? $payload : [];
    }

    private function cleanSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function cleanType(string $type): string
    {
        $allowed = ['hero', 'story', 'categories', 'showcase', 'faq'];
        return in_array($type, $allowed, true) ? $type : 'showcase';
    }

    private function isOptions(): bool
    {
        return strtolower($this->request->getMethod()) === 'options';
    }
}
