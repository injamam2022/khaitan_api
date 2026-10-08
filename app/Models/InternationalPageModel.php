<?php

namespace App\Models;

use CodeIgniter\Model;

class InternationalPageModel extends Model
{
    protected $table = 'intl_pages';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'name',
        'slug',
        'country',
        'status',
        'sort_order',
        'seo_title',
        'seo_description',
        'interest_options',
        'created_at',
        'updated_at',
    ];

    public function ensureSchema(): void
    {
        $created = false;
        $forge = \Config\Database::forge();

        if (!$this->db->tableExists('intl_pages')) {
            $forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 120],
                'slug' => ['type' => 'VARCHAR', 'constraint' => 160],
                'country' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
                'sort_order' => ['type' => 'INT', 'default' => 0],
                'seo_title' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true],
                'seo_description' => ['type' => 'VARCHAR', 'constraint' => 320, 'null' => true],
                'interest_options' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('id', true);
            $forge->addUniqueKey('slug');
            $forge->createTable('intl_pages', true);
            $created = true;
        }

        if (!$this->db->tableExists('intl_folds')) {
            $forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'page_id' => ['type' => 'INT', 'unsigned' => true],
                'fold_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'custom'],
                'title' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
                'body' => ['type' => 'TEXT', 'null' => true],
                'trust_line' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'cta_primary_label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'cta_primary_url' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
                'cta_secondary_label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'brochure_label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'brochure_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'brochure_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'payload' => ['type' => 'LONGTEXT', 'null' => true],
                'sort_order' => ['type' => 'INT', 'default' => 0],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('id', true);
            $forge->addKey('page_id');
            $forge->createTable('intl_folds', true);
            $created = true;
        }

        if (!$this->db->tableExists('intl_enquiries')) {
            $forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'page_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'page_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'page_slug' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
                'fold_title' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 200],
                'phone' => ['type' => 'VARCHAR', 'constraint' => 40],
                'interested_in' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'email' => ['type' => 'VARCHAR', 'constraint' => 255],
                'address' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'country' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'user_agent' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => false],
            ]);
            $forge->addKey('id', true);
            $forge->addKey('page_id');
            $forge->addKey('created_at');
            $forge->createTable('intl_enquiries', true);
        }

        if ($created && (int) $this->db->table('intl_pages')->countAllResults() === 0) {
            $this->seedSamples();
        }
    }

    public function listPages(bool $publishedOnly = false): array
    {
        $builder = $this->db->table('intl_pages');
        if ($publishedOnly) {
            $builder->where('status', 'published');
        }
        $rows = $builder->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        return array_map(fn ($row) => $this->formatPage($row, false), $rows);
    }

    public function findBySlug(string $slug, bool $publishedOnly = false): ?array
    {
        $builder = $this->db->table('intl_pages')->where('slug', $slug);
        if ($publishedOnly) {
            $builder->where('status', 'published');
        }
        $row = $builder->get()->getRowArray();
        if (!$row) {
            return null;
        }
        return $this->formatPage($row, true);
    }

    public function getPage(int $id, bool $withFolds = true): ?array
    {
        $row = $this->db->table('intl_pages')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return null;
        }
        return $this->formatPage($row, $withFolds);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $builder = $this->db->table('intl_pages')->where('slug', $slug);
        if ($ignoreId) {
            $builder->where('id !=', $ignoreId);
        }
        return $builder->countAllResults() > 0;
    }

    public function nextSortOrder(): int
    {
        $row = $this->db->table('intl_pages')->selectMax('sort_order', 'max_order')->get()->getRowArray();
        return (int) ($row['max_order'] ?? 0) + 1;
    }

    public function createPage(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('intl_pages')->insert([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'country' => $data['country'] ?? $data['name'],
            'status' => $data['status'] ?? 'draft',
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'interest_options' => json_encode($data['interest_options'] ?? $this->defaultInterests()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    public function updatePage(int $id, array $data): bool
    {
        $payload = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['name', 'slug', 'country', 'status', 'sort_order', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (array_key_exists('interest_options', $data) && is_array($data['interest_options'])) {
            $payload['interest_options'] = json_encode(array_values($data['interest_options']));
        }
        return $this->db->table('intl_pages')->where('id', $id)->update($payload);
    }

    public function deletePage(int $id): void
    {
        $this->db->table('intl_folds')->where('page_id', $id)->delete();
        $this->db->table('intl_pages')->where('id', $id)->delete();
    }

    public function duplicatePage(int $id): ?int
    {
        $page = $this->db->table('intl_pages')->where('id', $id)->get()->getRowArray();
        if (!$page) {
            return null;
        }
        $base = $page['slug'] . '-copy';
        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        $newId = $this->createPage([
            'name' => $page['name'] . ' copy',
            'slug' => $slug,
            'country' => $page['country'],
            'status' => 'draft',
            'seo_title' => $page['seo_title'],
            'seo_description' => $page['seo_description'],
            'interest_options' => json_decode((string) $page['interest_options'], true) ?: $this->defaultInterests(),
        ]);
        $folds = $this->db->table('intl_folds')->where('page_id', $id)->orderBy('sort_order', 'ASC')->get()->getResultArray();
        foreach ($folds as $fold) {
            unset($fold['id']);
            $fold['page_id'] = $newId;
            $fold['created_at'] = date('Y-m-d H:i:s');
            $fold['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table('intl_folds')->insert($fold);
        }
        return $newId;
    }

    public function foldsForPage(int $pageId): array
    {
        $rows = $this->db->table('intl_folds')
            ->where('page_id', $pageId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        return array_map(fn ($row) => $this->formatFold($row), $rows);
    }

    public function getFold(int $id): ?array
    {
        $row = $this->db->table('intl_folds')->where('id', $id)->get()->getRowArray();
        return $row ? $this->formatFold($row) : null;
    }

    public function addFold(int $pageId, string $type): int
    {
        $max = $this->db->table('intl_folds')->selectMax('sort_order', 'max_order')->where('page_id', $pageId)->get()->getRowArray();
        $defaults = $this->defaultFold($type);
        $now = date('Y-m-d H:i:s');
        $this->db->table('intl_folds')->insert([
            'page_id' => $pageId,
            'fold_type' => $type,
            'title' => $defaults['title'],
            'body' => $defaults['body'],
            'trust_line' => $defaults['trust_line'],
            'cta_primary_label' => $defaults['cta_primary_label'],
            'cta_primary_url' => $defaults['cta_primary_url'],
            'cta_secondary_label' => $defaults['cta_secondary_label'],
            'brochure_label' => $defaults['brochure_label'],
            'brochure_url' => null,
            'brochure_name' => null,
            'payload' => json_encode($defaults['payload']),
            'sort_order' => (int) ($max['max_order'] ?? 0) + 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    public function updateFold(int $id, array $data): bool
    {
        $payload = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ([
            'fold_type', 'title', 'body', 'trust_line',
            'cta_primary_label', 'cta_primary_url', 'cta_secondary_label',
            'brochure_label', 'brochure_url', 'brochure_name', 'sort_order', 'is_active',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (array_key_exists('payload', $data)) {
            $payload['payload'] = is_string($data['payload']) ? $data['payload'] : json_encode($data['payload']);
        }
        return $this->db->table('intl_folds')->where('id', $id)->update($payload);
    }

    public function deleteFold(int $id): void
    {
        $this->db->table('intl_folds')->where('id', $id)->delete();
    }

    public function saveEnquiry(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->table('intl_enquiries')->insert($data);
        return (int) $this->db->insertID();
    }

    public function listEnquiries(?int $pageId, int $limit, int $offset): array
    {
        $builder = $this->db->table('intl_enquiries');
        if ($pageId) {
            $builder->where('page_id', $pageId);
        }
        $total = (int) $builder->countAllResults(false);
        $rows = $builder->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        return ['rows' => $rows, 'total' => $total];
    }

    public function seedSamples(): void
    {
        if (!$this->slugExists('uae')) {
            $this->insertSamplePage($this->uaePage(), $this->uaeFolds());
        }
        if (!$this->slugExists('nepal')) {
            $this->insertSamplePage($this->shellPage('NEPAL', 'nepal', 'Nepal', 2), [
                $this->shellHero('Nepal'),
            ]);
        }
        if (!$this->slugExists('bhutan')) {
            $this->insertSamplePage($this->shellPage('BHUTAN', 'bhutan', 'Bhutan', 3), [
                $this->shellHero('Bhutan'),
            ]);
        }
    }

    public function defaultInterests(): array
    {
        return ['Air Coolers', 'Water Heaters', 'Ceiling Fans', 'Kitchen Appliances'];
    }

    private function insertSamplePage(array $page, array $folds): int
    {
        $id = $this->createPage($page);
        $order = 1;
        $now = date('Y-m-d H:i:s');
        foreach ($folds as $fold) {
            $this->db->table('intl_folds')->insert([
                'page_id' => $id,
                'fold_type' => $fold['fold_type'],
                'title' => $fold['title'] ?? '',
                'body' => $fold['body'] ?? '',
                'trust_line' => $fold['trust_line'] ?? '',
                'cta_primary_label' => $fold['cta_primary_label'] ?? '',
                'cta_primary_url' => $fold['cta_primary_url'] ?? '',
                'cta_secondary_label' => $fold['cta_secondary_label'] ?? 'Enquire',
                'brochure_label' => $fold['brochure_label'] ?? 'Download brochure',
                'brochure_url' => null,
                'brochure_name' => null,
                'payload' => json_encode($fold['payload'] ?? []),
                'sort_order' => $order++,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return $id;
    }

    private function formatPage(array $row, bool $withFolds): array
    {
        $options = json_decode((string) ($row['interest_options'] ?? ''), true);
        $row['interest_options'] = is_array($options) && $options !== [] ? array_values($options) : $this->defaultInterests();
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        if ($withFolds) {
            $row['folds'] = $this->foldsForPage((int) $row['id']);
        }
        return $row;
    }

    private function formatFold(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $row['id'] = (int) $row['id'];
        $row['page_id'] = (int) $row['page_id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = (int) $row['is_active'] === 1;
        if (!empty($row['brochure_url']) && !preg_match('/^https?:\/\//', $row['brochure_url'])) {
            $row['brochure_url'] = base_url($row['brochure_url']);
        }
        if (!empty($row['payload']['hero_image']) && is_string($row['payload']['hero_image']) && !preg_match('/^https?:\/\//', $row['payload']['hero_image'])) {
            $row['payload']['hero_image'] = base_url(ltrim($row['payload']['hero_image'], '/'));
        }
        return $row;
    }

    private function defaultFold(string $type): array
    {
        $base = [
            'title' => 'New section',
            'body' => '',
            'trust_line' => '',
            'cta_primary_label' => '',
            'cta_primary_url' => '',
            'cta_secondary_label' => 'Enquire',
            'brochure_label' => 'Download brochure',
            'payload' => ['anchor' => $type, 'items' => [], 'stats' => []],
        ];
        if ($type === 'hero') {
            $base['title'] = 'Page heading';
            $base['cta_primary_label'] = 'Explore Products';
            $base['cta_primary_url'] = '#fold-categories';
            $base['cta_secondary_label'] = 'Enquire for availability';
        }
        if ($type === 'story') {
            $base['title'] = 'About the brand';
            $base['payload']['stats'] = [
                ['value' => 'Since 1971', 'label' => ''],
                ['value' => '50+', 'label' => 'yrs'],
                ['value' => '200+', 'label' => 'Products'],
            ];
        }
        if ($type === 'faq') {
            $base['title'] = 'FAQs';
            $base['payload']['items'] = [
                ['question' => 'Question', 'answer' => 'Answer'],
            ];
        }
        return $base;
    }

    private function shellPage(string $name, string $slug, string $country, int $order): array
    {
        return [
            'name' => $name,
            'slug' => $slug,
            'country' => $country,
            'status' => 'draft',
            'sort_order' => $order,
            'seo_title' => 'Khaitan ' . $country,
            'seo_description' => 'Khaitan home appliances for ' . $country . '.',
            'interest_options' => $this->defaultInterests(),
        ];
    }

    private function shellHero(string $country): array
    {
        return [
            'fold_type' => 'hero',
            'title' => 'Khaitan for ' . $country,
            'body' => 'Add the ' . $country . ' page here. Each fold can carry its own brochure download, and the enquiry form opens from every section.',
            'trust_line' => 'Fans | Air Coolers | Water Heaters | Kitchen Appliances | Home Appliances',
            'cta_primary_label' => 'Explore Products',
            'cta_primary_url' => '#fold-categories',
            'cta_secondary_label' => 'Enquire for ' . $country . ' availability',
            'brochure_label' => 'Download brochure',
            'payload' => ['anchor' => 'hero'],
        ];
    }

    private function uaePage(): array
    {
        return [
            'name' => 'UAE',
            'slug' => 'uae',
            'country' => 'United Arab Emirates',
            'status' => 'published',
            'sort_order' => 1,
            'seo_title' => 'Khaitan UAE | Fans, Air Coolers, Water Heaters & Home Appliances',
            'seo_description' => 'Discover Khaitan fans, air coolers, water heaters, kitchen appliances and home appliances for modern UAE living.',
            'interest_options' => $this->defaultInterests(),
        ];
    }

    private function uaeFolds(): array
    {
        return [
            [
                'fold_type' => 'hero',
                'title' => 'Reliable Home Appliances for Modern UAE Living',
                'body' => 'Discover Khaitan’s range of fans, air coolers, water heaters, kitchen appliances and home appliances designed around everyday comfort, performance and convenience.',
                'trust_line' => 'Fans | Air Coolers | Water Heaters | Kitchen Appliances | Home Appliances',
                'cta_primary_label' => 'Explore Products',
                'cta_primary_url' => '#fold-categories',
                'cta_secondary_label' => 'Enquire for UAE Availability',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'hero',
                    'hero_image' => 'assets/banners/1771575379_ef023b788ac9d3b44c75.jpg',
                ],
            ],
            [
                'fold_type' => 'story',
                'title' => 'A Trusted Indian Electrical Brand Since 1971',
                'body' => 'Khaitan is an established Indian electrical brand offering fans, air coolers, water heaters, kitchen appliances and home appliances. For customers in the UAE, the range includes BLDC ceiling fans, personal and commercial air coolers, high-pressure water heaters and everyday kitchen appliances designed for comfort, convenience and practical performance.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'story',
                    'stats' => [
                        ['value' => 'Since 1971', 'label' => ''],
                        ['value' => '50+', 'label' => 'yrs'],
                        ['value' => '200+', 'label' => 'Products'],
                    ],
                ],
            ],
            [
                'fold_type' => 'categories',
                'title' => 'Product Categories',
                'body' => 'Browse the Khaitan ranges available for UAE homes, offices and commercial spaces.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'categories',
                    'items' => [
                        [
                            'title' => 'Ceiling Fans',
                            'href' => '/collections/ceiling-fans',
                            'image' => '/assets/img/ceillingfan.webp',
                            'usps' => ['BLDC and induction models', 'Homes and offices'],
                        ],
                        [
                            'title' => 'Air Coolers',
                            'href' => '/collections/desert-coolers',
                            'image' => '/assets/img/products/coolers/neacool1.webp',
                            'usps' => ['Personal and commercial', 'Multiple capacities'],
                        ],
                        [
                            'title' => 'Water Heaters',
                            'href' => '/collections/storage-geysers-water-heaters',
                            'image' => '/assets/img/products/geysers/aurusWhite1.webp',
                            'usps' => ['Storage and instant', 'Pressure-rated options'],
                        ],
                        [
                            'title' => 'Kitchen & Home Appliances',
                            'href' => '/pages/kitchen-appliance',
                            'image' => '/assets/img/products/mixers/grindmaster1.webp',
                            'usps' => ['Everyday kitchen use', 'Irons and home care'],
                        ],
                    ],
                ],
            ],
            [
                'fold_type' => 'showcase',
                'title' => 'Powerful Air Coolers for Homes & Commercial Spaces in the UAE',
                'body' => 'Stay comfortable in the UAE heat with Khaitan air coolers designed for homes, shops, offices, warehouses and commercial spaces. Explore personal and commercial air coolers with high air delivery, honeycomb cooling pads, powerful motors and large water tanks, available in multiple capacities to suit different room sizes and cooling requirements.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'air-coolers',
                    'items' => [
                        [
                            'title' => 'Personal Air Coolers',
                            'href' => '/collections/personal-coolers',
                            'image' => '/assets/img/products/coolers/cozy21l1.webp',
                            'usps' => ['Compact for bedrooms and small rooms', 'Honeycomb cooling pads', 'Easy to move between spaces'],
                        ],
                        [
                            'title' => 'Desert Air Coolers',
                            'href' => '/collections/desert-coolers',
                            'image' => '/assets/img/products/coolers/neacool1.webp',
                            'usps' => ['High air delivery', 'Large water tank', 'Suitable for homes and shops'],
                        ],
                        [
                            'title' => 'Commercial Air Coolers',
                            'href' => '/collections/commercial-coolers',
                            'image' => '/assets/img/products/coolers/bahubali110l1.webp',
                            'usps' => ['Powerful motor', 'Large tank capacity', 'Shops, offices and warehouses'],
                        ],
                    ],
                ],
            ],
            [
                'fold_type' => 'showcase',
                'title' => 'Energy-Efficient Ceiling Fans for Modern UAE Homes',
                'body' => 'Discover Khaitan ceiling fans designed for efficient airflow, quiet operation and everyday comfort. With aerodynamic blades, low power input, double ball bearings and smart remote-control features on selected models, Khaitan fans combine performance with modern design for homes, apartments, offices and commercial spaces across the UAE.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'ceiling-fans',
                    'items' => [
                        [
                            'title' => 'Whispair BLDC',
                            'href' => '/collections/ceiling-fans',
                            'image' => '/assets/img/ceillingfan.webp',
                            'usps' => ['Aerodynamic blades', 'Low power input', 'Smart remote on selected models'],
                        ],
                        [
                            'title' => 'Buddy BLDC',
                            'href' => '/collections/ceiling-fans',
                            'image' => '/assets/img/products/fans/dezirered.webp',
                            'usps' => ['Quiet everyday airflow', 'Double ball bearings', 'Suitable for apartments and offices'],
                        ],
                        [
                            'title' => 'Airwave BLDC',
                            'href' => '/collections/ceiling-fans',
                            'image' => '/assets/img/products/fans/toofangray.webp',
                            'usps' => ['Efficient airflow', 'Modern finish', 'Compare sweep and air delivery'],
                        ],
                    ],
                ],
            ],
            [
                'fold_type' => 'showcase',
                'title' => 'Khaitan Water Heaters for UAE Homes',
                'body' => 'Choose a water heater based on household size, installation requirements, capacity and pressure requirements.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'water-heaters',
                    'items' => [
                        [
                            'title' => 'Storage Water Heaters',
                            'href' => '/collections/storage-geysers-water-heaters',
                            'image' => '/assets/img/products/geysers/aurusWhite1.webp',
                            'usps' => ['Choose capacity by household size', 'Tank construction options', 'Check pressure rating before install'],
                        ],
                        [
                            'title' => 'Instant Water Heaters',
                            'href' => '/collections/instant-geysers-water-heaters',
                            'image' => '/assets/img/products/geysers/zodiakWhite1.webp',
                            'usps' => ['Compact installation', 'Quick hot water', 'Point-of-use convenience'],
                        ],
                        [
                            'title' => 'Glass Line Water Heaters',
                            'href' => '/collections/glass-line-water-heater',
                            'image' => '/assets/img/glassline.webp',
                            'usps' => ['Compare heating element specs', 'Match capacity to usage', 'Suitable for daily home use'],
                        ],
                    ],
                ],
            ],
            [
                'fold_type' => 'showcase',
                'title' => 'Kitchen & Home Appliances for Everyday Use',
                'body' => 'Practical Khaitan appliances for daily cooking and home care, with models you can compare by capacity, use and installation.',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'home-appliances',
                    'items' => [
                        [
                            'title' => 'Mixer Grinders',
                            'href' => '/collections/mixer-grinder',
                            'image' => '/assets/img/products/mixers/grindmaster1.webp',
                            'usps' => ['Everyday kitchen grinding', 'Multiple jars on selected models', 'Practical performance'],
                        ],
                        [
                            'title' => 'Irons',
                            'href' => '/collections/irons',
                            'image' => '/assets/img/products/irons/flatmatewhite1.webp',
                            'usps' => ['Daily garment care', 'Lightweight handling', 'Home and small-office use'],
                        ],
                        [
                            'title' => 'Kettles',
                            'href' => '/collections/kettle',
                            'image' => '/assets/img/products/kettle/kettle1.webp',
                            'usps' => ['Quick boiling', 'Compact countertop size', 'Simple daily use'],
                        ],
                    ],
                ],
            ],
            [
                'fold_type' => 'faq',
                'title' => 'Khaitan UAE FAQs – Air Coolers, BLDC Fans, Water Heaters & Home Appliances',
                'body' => '',
                'cta_secondary_label' => 'Enquire',
                'brochure_label' => 'Download brochure',
                'payload' => [
                    'anchor' => 'faqs',
                    'items' => [
                        [
                            'question' => 'What products does Khaitan offer in the UAE?',
                            'answer' => 'Khaitan offers a range of electrical and home appliances, including air coolers, BLDC ceiling fans, ceiling fans, water heaters, kitchen appliances and home appliances. The range includes products for everyday residential, office and commercial requirements across the UAE.',
                        ],
                        [
                            'question' => 'Does Khaitan offer air coolers in the UAE?',
                            'answer' => 'Yes. Khaitan offers personal and commercial air coolers in different capacities and configurations. Customers can compare air delivery, tank capacity, cooling pads and recommended room size to choose the right Khaitan air cooler in the UAE.',
                        ],
                        [
                            'question' => 'Khaitan air cooler is best for large rooms and commercial spaces?',
                            'answer' => 'Khaitan offers high-capacity commercial air coolers for larger spaces such as shops, offices, warehouses and other commercial areas. The right model can be selected based on room size, air delivery, tank capacity and cooling requirements.',
                        ],
                        [
                            'question' => 'What is a BLDC ceiling fan and why should I choose one?',
                            'answer' => 'A BLDC ceiling fan uses a brushless DC motor designed for efficient operation with lower power input on selected models. Khaitan BLDC fans combine efficient airflow with features such as aerodynamic blades, double ball bearings and smart remote functionality on selected models.',
                        ],
                        [
                            'question' => 'Which Khaitan BLDC ceiling fans are available?',
                            'answer' => 'Khaitan\'s BLDC range includes models such as Whispair, Buddy and Airwave. Customers can compare sweep, power input and air delivery to select a suitable Khaitan BLDC ceiling fan in the UAE.',
                        ],
                        [
                            'question' => 'Are Khaitan ceiling fans suitable for UAE homes and offices?',
                            'answer' => 'Yes. Khaitan ceiling fans can be used in homes, apartments, offices and other indoor spaces. Customers can compare sweep, air delivery, power input, design and available features according to their requirements.',
                        ],
                        [
                            'question' => 'Does Khaitan offer water heaters in the UAE?',
                            'answer' => 'Yes. Khaitan offers water heaters and geysers that can be compared based on capacity, heating technology, tank construction, pressure rating and heating element specifications. Customers can select a model according to their household and installation requirements.',
                        ],
                        [
                            'question' => 'How do I choose the right Khaitan water heater for my home?',
                            'answer' => 'Choose a Khaitan water heater based on household size, hot-water usage, required capacity, installation location and water-pressure requirements. Always check the individual model specifications before purchasing or installing a water heater.',
                        ],
                        [
                            'question' => 'Does Khaitan offer appliances for commercial use?',
                            'answer' => 'Yes. Khaitan\'s range includes products suitable for commercial applications, particularly commercial air coolers and high-air-delivery cooling solutions. Businesses can select products according to space size, air delivery, tank capacity and operating requirements.',
                        ],
                        [
                            'question' => 'Does Khaitan offer energy-efficient appliances?',
                            'answer' => 'Khaitan\'s portfolio includes energy-conscious products such as BLDC ceiling fans with low power input on selected models. Customers should check the individual product\'s rated power consumption and technical specifications when comparing energy-efficient appliances.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
