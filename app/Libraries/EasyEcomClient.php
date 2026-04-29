<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EasyEcom as EasyEcomConfig;

/**
 * EasyEcom API client (Self-Ship / Outbound Marketplace).
 * Handles JWT auth (DB-backed cache) and all outbound API calls.
 *
 * Token strategy (centralized — no other code should call /access/token):
 * 1. Check in-memory cache (same PHP request)
 * 2. Check DB cache (table from config: EASYECOM_TOKENS_TABLE, default easyecom_tokens)
 * 3. If token missing/expired: single-flight lock so only one process calls /access/token;
 *    others wait and re-read from DB (prevents 429 thundering herd)
 * 4. Fetch from /access/token API only in fetchNewToken() (no retry on 429 — fail fast)
 * 5. Save to both caches on success
 *
 * All token access must go through getValidAccessToken() (or getToken() alias).
 * The auth endpoint is never called outside this class.
 */
class EasyEcomClient
{
    private EasyEcomConfig $config;
    private ?string $jwtToken = null;
    private ?int $tokenExpiresAt = null;

    /** Refresh token this many seconds before actual expiry */
    private const TOKEN_BUFFER_SECONDS = 120;

    /** After 429, don't call /access/token again; base cooldown and exponential backoff */
    private ?int $authCooldownUntil = null;
    private const AUTH_COOLDOWN_BASE_SECONDS = 300;
    private const AUTH_COOLDOWN_MAX_SECONDS = 3600;
    private const AUTH_COOLDOWN_CACHE_KEY = 'easyecom_auth_cooldown_until';
    private const AUTH_429_COUNT_CACHE_KEY = 'easyecom_auth_429_count';

    /** Single-flight lock: only one process may call /access/token at a time (prevents 429 thundering herd) */
    private const TOKEN_FETCH_LOCK_KEY = 'easyecom_token_fetch_lock';
    private const TOKEN_FETCH_LOCK_TTL = 30;
    private const TOKEN_FETCH_WAIT_ATTEMPTS = 15;
    private const TOKEN_FETCH_WAIT_SECONDS = 2;

    /** Prevent more than one 401 retry per request (no aggressive retry loop) */
    private bool $request401Retried = false;

    public function __construct(?EasyEcomConfig $config = null)
    {
        $this->config = $config ?? config(EasyEcomConfig::class);
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Get a valid JWT token. Token is always taken from cache when possible;
     * /access/token is called only when cache is empty or expired.
     *
     * Order of lookup (token taken from local cache or DB first):
     * 1. In-memory cache (this request) — same client instance via service('easyecom')
     * 2. DB cache (table: EASYECOM_TOKENS_TABLE / easyecom_tokens)
     * 3. Only then: POST /access/token (single-flight lock to avoid 429)
     *
     * On 429: log once and throw; no retry loop.
     *
     * @throws \RuntimeException if not configured, auth fails, or rate-limited
     */
    public function getValidAccessToken(): string
    {
        log_message('info', 'EasyEcom: [AUTH] getValidAccessToken entered');

        if (! $this->config->isConfigured()) {
            throw new \RuntimeException('EasyEcom is not configured. Set EASYECOM_* in .env.');
        }

        $cooldownUntil = $this->getAuthCooldownUntil();
        if ($cooldownUntil !== null && time() < $cooldownUntil) {
            $remaining = $cooldownUntil - time();
            log_message('info', 'EasyEcom: [AUTH] Cooldown active from a previous 429 (shared across requests). Retry after ' . $remaining . 's.');
            throw new \RuntimeException('EasyEcom auth in cooldown (set by a previous request/process). Retry after ' . $remaining . 's.');
        }

        // 1. In-memory cache (local) — same PHP request reuses token
        if ($this->jwtToken !== null && $this->tokenExpiresAt !== null && time() < $this->tokenExpiresAt) {
            log_message('info', 'EasyEcom: [AUTH] Token from in-memory cache.');
            return $this->jwtToken;
        }

        // 2. DB cache
        $table = $this->getTokensTableName();
        try {
            $db      = \Config\Database::connect();
            $builder = $db->table($table);
            $row     = $builder->limit(1)->get()->getRowArray();

            if (! empty($row) && ! empty($row['access_token']) && ! empty($row['expires_at'])) {
                $expiresAt = strtotime($row['expires_at']);
                if ($expiresAt !== false && $expiresAt > time()) {
                    $this->jwtToken       = (string) $row['access_token'];
                    $this->tokenExpiresAt = $expiresAt;
                    log_message('info', 'EasyEcom: [AUTH] Token from DB cache (table=' . $table . ').');
                    return $this->jwtToken;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] DB lookup failed: ' . $e->getMessage());
        }

        // 3. Cache miss — fetch from /access/token (single-flight to avoid 429)
        log_message('info', 'EasyEcom: [AUTH] Token not in in-memory or DB cache; will fetch from API if lock acquired.');
        if (! $this->tryAcquireTokenFetchLock()) {
            $token = $this->waitForTokenFromOtherProcess($table);
            if ($token !== null) {
                return $token;
            }
            // Lock may have been released; re-check cooldown before fetching (another process may have hit 429)
            $cooldownUntil = $this->getAuthCooldownUntil();
            if ($cooldownUntil !== null && time() < $cooldownUntil) {
                $remaining = $cooldownUntil - time();
                log_message('info', 'EasyEcom: [AUTH] Cooldown active after wait. Retry after ' . $remaining . 's.');
                throw new \RuntimeException('EasyEcom auth in cooldown. Retry after ' . $remaining . 's.');
            }
        }

        try {
            return $this->fetchNewToken();
        } finally {
            $this->releaseTokenFetchLock();
        }
    }

    /**
     * Backwards-compatible alias for existing callers.
     *
     * All new code should use getValidAccessToken().
     *
     * @throws \RuntimeException
     */
    public function getToken(): string
    {
        return $this->getValidAccessToken();
    }

    /**
     * Make an authenticated request (GET or POST) with JWT + x-api-key.
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $this->request401Retried = false;

        log_message('info', 'EasyEcom: TRIGGERED API ' . strtoupper($method) . ' ' . $path);

        $token  = $this->getValidAccessToken();
        $url    = str_starts_with($path, 'http') ? $path : rtrim($this->config->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        log_message('info', 'EasyEcom: [API] ' . strtoupper($method) . ' ' . $url);
        $client = $this->getHttpClient();

        $options = [
            'http_errors' => false,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'x-api-key'     => $this->config->xApiKey,
            ],
        ];
        if ($body !== []) {
            $options['json'] = $body;
        }

        $response = strtoupper($method) === 'GET'
            ? $client->get($url, $options)
            : $client->post($url, $options);

        $status = $response->getStatusCode();

        if ($status === 401 && ! $this->request401Retried) {
            $this->request401Retried = true;
            log_message('info', 'EasyEcom: [API] 401 received, clearing token cache and retrying once');
            $this->clearTokenCache();
            $token = $this->getValidAccessToken();
            $options['headers']['Authorization'] = 'Bearer ' . $token;
            $response = strtoupper($method) === 'GET'
                ? $client->get($url, $options)
                : $client->post($url, $options);
            $status = $response->getStatusCode();
        }

        log_message('info', 'EasyEcom: [API] response status=' . $status);
        return $this->decodeResponse($response, $url);
    }

    // -----------------------------------------------------------------------
    // Public API methods
    // -----------------------------------------------------------------------

    public function getProductMaster(array $params = []): array
    {
        return $this->request('GET', '/Products/GetProductMaster', [], $params);
    }

    public function createMasterProduct(array $payload): array
    {
        return $this->request('POST', '/Products/CreateMasterProduct', $payload);
    }

    public function updateMasterProduct(array $payload): array
    {
        return $this->request('POST', '/Products/UpdateMasterProduct', $payload);
    }

    public function createOrder(array $payload): array
    {
        if (!isset($payload['is_market_shipped'])) {
            $payload['is_market_shipped'] = 0;
        }
        if (!isset($payload['cp_auto_create'])) {
            $payload['cp_auto_create'] = 1;
        }
        $path = '/' . ltrim($this->config->createOrderPath ?? 'webhook/v2/createOrder', '/');
        log_message('info', 'EasyEcom: [CREATE_ORDER] orderNumber=' . ($payload['orderNumber'] ?? '') . ' items=' . count($payload['items'] ?? []));
        return $this->request('POST', $path, $payload);
    }

    /**
     * Cancel order on EasyEcom.
     * Endpoint: /orders/cancelOrder
     */
    public function cancelOrder($orderRef): array
    {
        $body = is_array($orderRef) ? $orderRef : ['reference_code' => (string) $orderRef];
        log_message('info', 'EasyEcom: [CancelOrder] reference_code=' . ($body['reference_code'] ?? ''));
        return $this->request('POST', '/orders/cancelOrder', $body);
    }

    /**
     * Confirm order on EasyEcom to move it from Pending to Confirmed.
     * Endpoint: /orders/confirm_order
     */
    public function confirmOrder($orderId, array $dimensions = []): array
    {
        // Dimensions can include: height, width, length, weight
        $queryParams = array_merge(['order_id' => $orderId], $dimensions);
        log_message('info', 'EasyEcom: [ConfirmOrder] order_id=' . $orderId);
        return $this->request('POST', '/orders/confirm_order', [], $queryParams);
    }

    /**
     * Update order address on EasyEcom.
     * Endpoint: /orders/updateOrderAddress
     */
    public function updateOrderAddress(array $data): array
    {
        log_message('info', 'EasyEcom: [UpdateAddress] order_id=' . ($data['order_id'] ?? ''));
        return $this->request('POST', '/orders/updateOrderAddress', $data);
    }

    /**
     * Get details of a specific order.
     * Endpoint: /orders/V2/getOrderDetails
     */
    public function getOrderDetails(array $params): array
    {
        return $this->request('GET', '/orders/V2/getOrderDetails', [], $params);
    }

    public function getInventoryDetails(array $params = []): array
    {
        if (empty($params['locationKey'])) {
            $params['locationKey'] = $this->config->locationKey;
        }
        return $this->request('GET', '/Inventory/GetInventoryDetails', [], $params);
    }

    /**
     * Update actual inventory for a single SKU.
     * Calls POST /inventory. SKU must match the one used in CreateMasterProduct.
     *
     * @param string $sku
     * @param int    $quantity
     * @return array
     */
    public function syncInventory(string $sku, int $quantity): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('EasyEcom is not configured. Set EASYECOM_* in .env.');
        }

        $sku = trim($sku);
        if ($sku === '') {
            log_message('info', 'EasyEcom: [INVENTORY] skipped sku empty');
            return ['success' => false, 'message' => 'SKU is empty; inventory update skipped'];
        }

        $quantity = max(0, (int) $quantity);
        $payload  = [
            'sku'      => $sku,
            'quantity' => $quantity,
        ];

        log_message('info', 'EasyEcom: [INVENTORY] calling /inventory sku=' . $sku . ' quantity=' . $quantity);
        $response = $this->request('POST', '/inventory', $payload);
        $this->assertInventorySuccess($response, 'inventory update', 'sku=' . $sku . ' quantity=' . $quantity);

        log_message('info', 'EasyEcom: [INVENTORY] updated sku=' . $sku . ' quantity=' . $quantity);
        return $response;
    }

    /**
     * Sync virtual inventory for a single SKU (WMS accounts).
     * Calls POST /updateVirtualInventoryAPI. SKU must match the one used in CreateMasterProduct.
     */
    public function syncVirtualInventory(string $sku, int $quantity): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('EasyEcom is not configured. Set EASYECOM_* in .env.');
        }

        $sku = trim($sku);
        if ($sku === '') {
            log_message('info', 'EasyEcom: [VIRTUAL_INVENTORY] skipped sku empty');
            return ['success' => false, 'message' => 'SKU is empty; virtual inventory update skipped'];
        }

        $quantity = max(0, (int) $quantity);
        $payload  = [
            'skus' => [
                ['sku' => $sku, 'virtual_invent_count' => $quantity],
            ],
        ];

        log_message('info', 'EasyEcom: [VIRTUAL_INVENTORY] calling updateVirtualInventoryAPI sku=' . $sku . ' virtual_invent_count=' . $quantity);
        $response = $this->request('POST', '/updateVirtualInventoryAPI', $payload);
        $this->assertInventorySuccess($response, 'virtual inventory update', 'sku=' . $sku . ' quantity=' . $quantity);

        log_message('info', 'EasyEcom: [VIRTUAL_INVENTORY] updated sku=' . $sku . ' quantity=' . $quantity);
        return $response;
    }

    /**
     * Update inventory for a single SKU. Delegates to syncInventory (/inventory API).
     *
     * @deprecated Prefer syncInventory() for clarity. Kept for backward compatibility.
     */
    public function updateInventory(string $sku, int $quantity): array
    {
        return $this->syncInventory($sku, $quantity);
    }

    /**
     * Bulk update virtual inventory. Uses POST /updateVirtualInventoryAPI (virtual_invent_count).
     */
    public function bulkInventoryUpdate(array $skus): array
    {
        return $this->bulkVirtualInventoryUpdate($skus);
    }

    /**
     * Bulk update via POST /updateVirtualInventoryAPI (virtual_invent_count).
     */
    public function bulkVirtualInventoryUpdate(array $skus): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('EasyEcom is not configured. Set EASYECOM_* in .env.');
        }

        $items = [];
        foreach ($skus as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            $qty = isset($item['quantity']) ? max(0, (int) $item['quantity']) : 0;
            if ($sku === '') {
                continue;
            }
            $items[] = ['sku' => $sku, 'virtual_invent_count' => $qty];
        }
        if ($items === []) {
            return ['success' => true, 'message' => 'No valid SKUs to update'];
        }

        $response = $this->request('POST', '/updateVirtualInventoryAPI', ['skus' => $items]);
        $this->assertInventorySuccess($response, 'virtual inventory update', 'items=' . count($items));

        log_message('info', 'EasyEcom: [VIRTUAL_INVENTORY] success items=' . count($items));
        return $response;
    }

    public function getAllOrders(array $params = []): array
    {
        return $this->request('GET', '/orders/V2/getAllOrders', [], $params);
    }

    // -----------------------------------------------------------------------
    // Static helpers
    // -----------------------------------------------------------------------

    public static function isApiFailure(array $response): bool
    {
        if (isset($response['success']) && $response['success'] === false) {
            return true;
        }
        if (isset($response['error'])) {
            return true;
        }
        if (isset($response['_status']) && $response['_status'] >= 400) {
            return true;
        }
        if (isset($response['code']) && is_numeric($response['code']) && (int) $response['code'] >= 400) {
            return true;
        }
        return false;
    }

    public static function extractErrorMessage(array $response): string
    {
        return is_string($response['message'] ?? null) && $response['message'] !== ''
            ? $response['message']
            : (is_string($response['error'] ?? null) && $response['error'] !== ''
                ? $response['error']
                : json_encode($response));
    }

    public function getLocationKey(): string
    {
        return $this->config->locationKey;
    }

    // -----------------------------------------------------------------------
    // Token management (DB-backed cache, fail-fast on 429)
    // -----------------------------------------------------------------------
    
    private function fetchNewToken(): string
    {
        $client    = $this->getHttpClient();
        $loginPath = '/' . ltrim($this->config->loginPath ?? 'access/token', '/');
        $url       = rtrim($this->config->baseUrl, '/') . $loginPath;
        $body      = [
            'email'        => $this->config->apiUsername,
            'password'     => $this->config->apiPassword,
            'location_key' => $this->config->locationKey,
        ];

        log_message('info', 'EasyEcom: [AUTH] Fetching token from API.');

        try {
            $response = $client->post($url, [
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key'   => $this->config->xApiKey,
                ],
                'json' => $body,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] Request failed: ' . $e->getMessage());
            throw new \RuntimeException('EasyEcom auth request failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            $cooldownSeconds = $this->compute429CooldownSeconds($response);
            $this->increment429Count();
            $this->setAuthCooldown($cooldownSeconds);
            log_message('error', 'EasyEcom: [AUTH] 429 rate-limited. Cooldown ' . $cooldownSeconds . 's (do not call /access/token until cooldown ends).');
            throw new \RuntimeException('EasyEcom auth rate-limited (429). Wait ' . $cooldownSeconds . 's before retry.');
        }

        $data  = $this->decodeResponse($response, $url);
        $token = $this->extractTokenFromResponse($data);

        if (empty($token) || ! is_string($token)) {
            $msg = $data['message'] ?? $data['error'] ?? 'No token in response';
            $shortMsg = is_string($msg) ? $msg : 'No token in response';
            log_message('error', 'EasyEcom: [AUTH] ' . $shortMsg);
            throw new \RuntimeException('EasyEcom auth failed: ' . $shortMsg);
        }

        $expiresIn         = $this->extractExpiresIn($data);
        $effectiveExpiresAt = time() + max(0, $expiresIn - self::TOKEN_BUFFER_SECONDS);

        $this->jwtToken       = $token;
        $this->tokenExpiresAt = $effectiveExpiresAt;
        $this->clearAuthCooldown();
        $this->clear429Count();
        $this->saveTokenToDb($token, $effectiveExpiresAt);

        log_message('info', 'EasyEcom: [AUTH] Token saved.');
        return $this->jwtToken;
    }

    /**
     * Read the global auth cooldown timestamp (persisted in cache).
     */
    private function getAuthCooldownUntil(): ?int
    {
        if ($this->authCooldownUntil !== null) {
            return $this->authCooldownUntil;
        }

        try {
            $cache = service('cache');
            if ($cache === null) {
                return null;
            }
            $value = $cache->get(self::AUTH_COOLDOWN_CACHE_KEY);
            if ($value === null) {
                return null;
            }
            if (is_int($value)) {
                $this->authCooldownUntil = $value;
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                $this->authCooldownUntil = (int) $value;
                return $this->authCooldownUntil;
            }
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] Cooldown cache read failed.');
        }

        return null;
    }

    /**
     * Set the global auth cooldown after a 429 response.
     */
    private function setAuthCooldown(int $seconds): void
    {
        $seconds = max(1, min($seconds, self::AUTH_COOLDOWN_MAX_SECONDS));
        $until   = time() + $seconds;
        $this->authCooldownUntil = $until;

        try {
            $cache = service('cache');
            if ($cache !== null) {
                $cache->save(self::AUTH_COOLDOWN_CACHE_KEY, $until, $seconds + 60);
            }
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] Cooldown cache write failed.');
        }
    }

    /**
     * Compute cooldown seconds for 429:
     * - If EASYECOM_AUTH_TEST_COOLDOWN_SECONDS is set (and >0), use that (for testing).
     * - Else, use Retry-After header if present.
     * - Else, use exponential backoff from AUTH_COOLDOWN_BASE_SECONDS, capped at AUTH_COOLDOWN_MAX_SECONDS.
     */
    private function compute429CooldownSeconds(ResponseInterface $response): int
    {
        // Explicit testing override: set EASYECOM_AUTH_TEST_COOLDOWN_SECONDS=5 in .env to use 5s cooldown.
        $testCooldown = env('EASYECOM_AUTH_TEST_COOLDOWN_SECONDS', null);
        if ($testCooldown !== null && is_numeric($testCooldown)) {
            $seconds = (int) $testCooldown;
            if ($seconds > 0) {
                return $seconds;
            }
        }

        $retryAfter = $response->getHeaderLine('Retry-After');
        if ($retryAfter !== '') {
            $seconds = (int) $retryAfter;
            if ($seconds > 0) {
                return max(60, min(self::AUTH_COOLDOWN_MAX_SECONDS, $seconds));
            }
        }

        $count = $this->get429Count();
        $backoff = self::AUTH_COOLDOWN_BASE_SECONDS * (2 ** min($count, 4));
        return (int) min(self::AUTH_COOLDOWN_MAX_SECONDS, $backoff);
    }

    private function get429Count(): int
    {
        try {
            $cache = service('cache');
            if ($cache === null) {
                return 0;
            }
            $v = $cache->get(self::AUTH_429_COUNT_CACHE_KEY);
            return is_numeric($v) ? (int) $v : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function increment429Count(): void
    {
        try {
            $cache = service('cache');
            if ($cache === null) {
                return;
            }
            $count = $this->get429Count() + 1;
            $cache->save(self::AUTH_429_COUNT_CACHE_KEY, $count, 86400);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function clear429Count(): void
    {
        try {
            $cache = service('cache');
            if ($cache !== null) {
                $cache->delete(self::AUTH_429_COUNT_CACHE_KEY);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Clear any existing auth cooldown (after successful token fetch).
     */
    private function clearAuthCooldown(): void
    {
        $this->authCooldownUntil = null;
        try {
            $cache = service('cache');
            if ($cache !== null) {
                $cache->delete(self::AUTH_COOLDOWN_CACHE_KEY);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Acquire single-flight lock so only one process calls /access/token.
     * Returns true if lock acquired, false if another process holds it.
     */
    private function tryAcquireTokenFetchLock(): bool
    {
        try {
            $cache = service('cache');
            if ($cache === null) {
                return true;
            }
            if ($cache->get(self::TOKEN_FETCH_LOCK_KEY) !== null) {
                return false;
            }
            $cache->save(self::TOKEN_FETCH_LOCK_KEY, time(), self::TOKEN_FETCH_LOCK_TTL);
            return $cache->get(self::TOKEN_FETCH_LOCK_KEY) !== null;
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] Token fetch lock acquire failed.');
            return true;
        }
    }

    /**
     * Release single-flight lock after token fetch (success or failure).
     */
    private function releaseTokenFetchLock(): void
    {
        try {
            $cache = service('cache');
            if ($cache !== null) {
                $cache->delete(self::TOKEN_FETCH_LOCK_KEY);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * When another process holds the token-fetch lock, wait and re-check DB;
     * another process may have written a valid token. Returns token string or null.
     */
    private function waitForTokenFromOtherProcess(string $table): ?string
    {
        for ($i = 0; $i < self::TOKEN_FETCH_WAIT_ATTEMPTS; $i++) {
            log_message('info', 'EasyEcom: [AUTH] Another process is fetching token; waiting ' . self::TOKEN_FETCH_WAIT_SECONDS . 's (attempt ' . ($i + 1) . '/' . self::TOKEN_FETCH_WAIT_ATTEMPTS . ').');
            sleep(self::TOKEN_FETCH_WAIT_SECONDS);
            try {
                $db  = \Config\Database::connect();
                $row = $db->table($table)->limit(1)->get()->getRowArray();
                if (! empty($row['access_token']) && ! empty($row['expires_at'])) {
                    $expiresAt = strtotime($row['expires_at']);
                    if ($expiresAt !== false && $expiresAt > time()) {
                        $this->jwtToken       = (string) $row['access_token'];
                        $this->tokenExpiresAt = $expiresAt;
                        log_message('info', 'EasyEcom: Using token written by another process.');
                        return $this->jwtToken;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'EasyEcom: [AUTH] DB recheck failed: ' . $e->getMessage());
            }
        }
        return null;
    }

    private function isTokenValid(): bool
    {
        return $this->jwtToken !== null
            && $this->tokenExpiresAt !== null
            && time() < ($this->tokenExpiresAt - self::TOKEN_BUFFER_SECONDS);
    }

    private function extractTokenFromResponse(array $data): ?string
    {
        $tokenObj = $data['data']['token'] ?? null;
        $token = null;
        if (is_array($tokenObj) && !empty($tokenObj['jwt_token'])) {
            $token = $tokenObj['jwt_token'];
        } elseif (is_string($tokenObj)) {
            $token = $tokenObj;
        }
        return $token ?? $data['jwt_token'] ?? $data['token'] ?? $data['access_token'] ?? null;
    }

    private function extractExpiresIn(array $data): int
    {
        if (isset($data['data']['token']['expires_in'])) {
            return (int) $data['data']['token']['expires_in'];
        }
        if (isset($data['expires_in'])) {
            return (int) $data['expires_in'];
        }
        return 3600;
    }

    // -----------------------------------------------------------------------
    // DB-backed token cache
    // -----------------------------------------------------------------------

    private function getTokensTableName(): string
    {
        return $this->config->tokensTable ?? 'easyecom_tokens';
    }

    private function loadTokenFromDb(): ?array
    {
        $table = $this->getTokensTableName();
        try {
            $db     = \Config\Database::connect();
            $result = $db->table($table)->orderBy('id', 'DESC')->limit(1)->get();
            $row    = is_object($result) ? $result->getRowArray() : null;

            if (empty($row['access_token']) || empty($row['expires_at'])) {
                return null;
            }
            $expiresAt = strtotime($row['expires_at']);
            if ($expiresAt === false || time() >= ($expiresAt - self::TOKEN_BUFFER_SECONDS)) {
                return null;
            }
            return ['token' => $row['access_token'], 'expires_at' => $expiresAt];
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] DB lookup failed.');
            return null;
        }
    }

    private function saveTokenToDb(string $token, int $expiresAt): void
    {
        $table        = $this->getTokensTableName();
        $expiresAtStr  = date('Y-m-d H:i:s', $expiresAt);
        $db            = \Config\Database::connect();
        $builder       = $db->table($table);

        log_message('info', 'EasyEcom: [AUTH] saveTokenToDb before truncate table=' . $table);

        try {
            $builder->truncate();
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] Token table truncate failed: ' . $e->getMessage());
            throw new \RuntimeException('EasyEcom token table truncate failed.', 0, $e);
        }

        log_message('info', 'EasyEcom: [AUTH] saveTokenToDb before insert table=' . $table . ' expires_at=' . $expiresAtStr);
        $builder->insert([
            'access_token' => $token,
            'expires_at'   => $expiresAtStr,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $affected = $db->affectedRows();
        log_message('info', 'EasyEcom: [AUTH] saveTokenToDb after insert affectedRows=' . $affected);

        if ($affected < 1) {
            log_message('error', 'EasyEcom: [AUTH] Token DB insert failed (affectedRows=0).');
            throw new \RuntimeException('Token persistence failed');
        }
    }

    private function clearTokenCache(): void
    {
        $this->jwtToken = null;
        $this->tokenExpiresAt = null;

        try {
            $db = \Config\Database::connect();
            $db->table($this->getTokensTableName())->truncate();
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [AUTH] DB token clear failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function normalizeSkuQuantityList(array $skus): array
    {
        $items = [];
        foreach ($skus as $item) {
            if (!is_array($item)) continue;
            $sku = trim((string) ($item['sku'] ?? ''));
            $qty = isset($item['quantity']) ? max(0, (int) $item['quantity']) : 0;
            if ($sku === '') continue;
            $items[] = ['sku' => $sku, 'quantity' => $qty];
        }
        return $items;
    }

    private function assertInventorySuccess(array $response, string $operation, string $context): void
    {
        $isFailure = (isset($response['_status']) && $response['_status'] !== 200)
            || (isset($response['code']) && is_numeric($response['code']) && (int) $response['code'] !== 200);

        if (! $isFailure) {
            return;
        }

        $statusPart  = isset($response['_status']) ? ('HTTP status ' . $response['_status']) : 'HTTP error';
        $messagePart = $response['message'] ?? $response['error'] ?? null;
        $errorMsg    = (is_string($messagePart) && $messagePart !== '')
            ? $statusPart . ' - ' . $messagePart
            : $statusPart . ' from EasyEcom ' . $operation;

        log_message('error', 'EasyEcom: ' . $operation . ' failed ' . $context . ' response=' . json_encode($response));
        throw new \RuntimeException('EasyEcom ' . $operation . ' failed: ' . $errorMsg);
    }

    private function getHttpClient(): CURLRequest
    {
        return service('curlrequest', [
            'baseURI' => $this->config->baseUrl,
            'timeout' => 30,
        ]);
    }

    private function decodeResponse(ResponseInterface $response, string $url): array
    {
        $status = $response->getStatusCode();
        $raw    = $response->getBody();
        $data   = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message('error', 'EasyEcom: [API] non-JSON response from ' . $url . ': ' . substr($raw, 0, 500));
            return ['_raw' => $raw, '_status' => $status];
        }
        if ($status < 200 || $status >= 300) {
            log_message('error', 'EasyEcom: [API] error status=' . $status . ' url=' . $url . ' response=' . json_encode($data));
        }
        $data['_status'] = $status;
        return $data;
    }
}
