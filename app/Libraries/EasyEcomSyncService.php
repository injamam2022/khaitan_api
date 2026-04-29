<?php

namespace App\Libraries;

use App\Models\EasyEcomOrderMappingModel;
use App\Models\EasyEcomSkuMappingModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Services\EasyEcomCarrierService;

/**
 * Central business logic for EasyEcom API synchronisation.
 * Called directly from controllers — no queue intermediary.
 *
 * Each public method returns ['success' => bool, 'message' => string, 'data' => [...]].
 */
class EasyEcomSyncService
{
    private EasyEcomClient $client;
    private EasyEcomCarrierService $carrierService;
    private ProductModel $productModel;
    private OrderModel $orderModel;
    private EasyEcomSkuMappingModel $skuMapping;
    private EasyEcomOrderMappingModel $orderMapping;

    public function __construct()
    {
        // Use shared singleton only — never new EasyEcomClient() (prevents duplicate /access/token calls)
        $this->client = service('easyecom');
        $this->carrierService = service('easyecomCarrier');
        $this->productModel = new ProductModel();
        $this->orderModel = new OrderModel();
        $this->skuMapping = new EasyEcomSkuMappingModel();
        $this->orderMapping = new EasyEcomOrderMappingModel();
    }

    /**
     * Non-blocking helper: call any method on this service, catch all exceptions,
     * and always return a result array. Controllers use this so that EasyEcom
     * failures never break the admin response.
     *
     * Usage: EasyEcomSyncService::fire(fn ($s) => $s->syncProduct($id, $payload));
     *
     * Set EASYECOM_SYNC_ENABLED=true in .env to enable sync (default off to avoid auth 429).
     */
    public static function fire(callable $callback): array
    {
        // Always log to PHP error_log so you see it in server error log even if writable/logs fails
        error_log('EasyEcomSyncService::fire called (EASYECOM_SYNC_ENABLED=' . (env('EASYECOM_SYNC_ENABLED', false) ? 'true' : 'false') . ')');

        $enabled = filter_var(env('EASYECOM_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return ['success' => true, 'message' => 'EasyEcom sync disabled (EASYECOM_SYNC_ENABLED not set or false)', 'data' => []];
        }

        error_log('EasyEcom: TRIGGERED sync (sync enabled)');
        log_message('info', 'EasyEcom: TRIGGERED sync');

        try {
            $service = new static();
            return $callback($service);
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [FIRE] ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // -----------------------------------------------------------------------
    // Product sync
    // -----------------------------------------------------------------------

    public function syncProduct(int $productId, array $payload = []): array
    {
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 1 started entity_id=' . $productId . ' payload_keys=' . (empty($payload) ? 'none' : implode(',', array_keys($payload))));

        if (!$this->client->isConfigured()) {
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 2 FAILED entity_id=' . $productId . ' reason=EasyEcom not configured');
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 2 passed entity_id=' . $productId . ' client is configured');

        $db = \Config\Database::connect();
        $existing = $db->table('products')->select('easyecom_product_id, sku_number')->where('id', $productId)->get()->getRowArray();
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 3 DB fetch entity_id=' . $productId . ' found=' . ($existing ? 'yes' : 'no') . (isset($existing['sku_number']) ? ' sku=' . $existing['sku_number'] : '') . (isset($existing['easyecom_product_id']) ? ' easyecom_id=' . $existing['easyecom_product_id'] : ''));

        if (!$existing) {
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 3 FAILED entity_id=' . $productId . ' reason=Product not found in DB');
            return ['success' => false, 'message' => 'Product not found in DB', 'data' => []];
        }
        if (!empty($existing['easyecom_product_id'])) {
            log_message('info', 'EasyEcom: [SYNC_PRODUCT] entity_id=' . $productId . ' found easyecom_id=' . $existing['easyecom_product_id'] . ', proceeding with update');
            $productDetails = $this->productModel->getProductDetails($productId);
            if ($productDetails) {
                $payload = EasyEcomPayloadBuilder::buildUpdateProductPayload($existing['easyecom_product_id'], $productDetails);
                $response = $this->client->updateMasterProduct($payload);
                if (!EasyEcomClient::isApiFailure($response)) {
                    return ['success' => true, 'message' => 'Product details updated', 'data' => ['easyecom_product_id' => $existing['easyecom_product_id']]];
                }
                return ['success' => false, 'message' => EasyEcomClient::extractErrorMessage($response), 'data' => $response];
            }
            return ['success' => true, 'message' => 'Product already synced, update skipped (no details)', 'data' => ['easyecom_product_id' => $existing['easyecom_product_id']]];
        }

        $sku = trim((string) ($existing['sku_number'] ?? ''));
        if ($sku === '') {
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 5 FAILED entity_id=' . $productId . ' reason=Product has no SKU');
            return ['success' => false, 'message' => 'Product has no SKU', 'data' => []];
        }

        // NEW: Skip parent products that have variations (each variation is synced as a separate product)
        $hasVariations = $db->table('product_variations')->where('product_id', $productId)->where('is_deleted', 0)->countAllResults() > 0;
        if ($hasVariations) {
            log_message('info', 'EasyEcom: [SYNC_PRODUCT] entity_id=' . $productId . ' reason=skipping parent product, has variations');
            return ['success' => true, 'message' => 'Skipping parent product as it has variations', 'data' => []];
        }
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 5 passed entity_id=' . $productId . ' sku=' . $sku);

        $product = $this->productModel->getProductDetails($productId);
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 6 getProductDetails entity_id=' . $productId . ' found=' . (empty($product) ? 'no' : 'yes'));

        if (empty($product)) {
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 6 FAILED entity_id=' . $productId . ' reason=Product details not found');
            return ['success' => false, 'message' => 'Product details not found', 'data' => []];
        }

        if (!empty($payload)) {
            $product = array_merge($product, $payload);
            log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 7 merged payload into product entity_id=' . $productId);
        }

        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 8 resolving description and image entity_id=' . $productId);
        $description = $this->resolveProductDescription($productId);
        $imageUrl = $this->resolveProductImageUrl($productId);
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 8 done entity_id=' . $productId . ' description_len=' . strlen($description) . ' imageUrl_len=' . strlen($imageUrl));

        $apiPayload = EasyEcomPayloadBuilder::buildCreateProductPayload($product, $description, $imageUrl);
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 9 payload built entity_id=' . $productId . ' payload_keys=' . implode(',', array_keys($apiPayload)) . ' Sku=' . ($apiPayload['Sku'] ?? '') . ' ProductName=' . ($apiPayload['ProductName'] ?? ''));

        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 10 calling CreateMasterProduct API entity_id=' . $productId);
        $response = $this->client->createMasterProduct($apiPayload);
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 10 API returned entity_id=' . $productId . ' response_keys=' . (is_array($response) ? implode(',', array_keys($response)) : gettype($response)));

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 11 FAILED API error entity_id=' . $productId . ' message=' . $errorMsg . ' response=' . json_encode($response));
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }
        log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 11 API success (no failure) entity_id=' . $productId);

        $eeProductId = $response['product_id'] ?? $response['ProductId'] ?? $response['data']['product_id'] ?? $response['data']['ProductId'] ?? $response['id'] ?? null;
        if ($eeProductId !== null && $eeProductId !== '') {
            $eeProductId = (string) $eeProductId;
            $db->table('products')->where('id', $productId)->update(['easyecom_product_id' => $eeProductId]);
            log_message('info', 'EasyEcom: [SYNC_PRODUCT] STEP 12 SUCCESS entity_id=' . $productId . ' easyecom_id=' . $eeProductId . ' DB updated');

            $this->triggerPostCreationInventorySync($productId, $product);

            return ['success' => true, 'message' => 'Product synced', 'data' => ['easyecom_product_id' => $eeProductId]];
        }

        log_message('error', 'EasyEcom: [SYNC_PRODUCT] STEP 12 FAILED null EasyEcom product_id entity_id=' . $productId . ' response_keys=' . (is_array($response) ? implode(',', array_keys($response)) : '') . ' raw_response=' . json_encode($response));
        return ['success' => false, 'message' => 'No product_id returned from EasyEcom API', 'data' => $response];
    }

    public function updateProduct(int $productId): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $db = \Config\Database::connect();
        $product = $db->table('products')->where('id', $productId)->get()->getRowArray();

        if (empty($product) || empty($product['easyecom_product_id'])) {
            return ['success' => true, 'message' => 'Product not synced to EasyEcom yet, skipping update', 'data' => []];
        }

        // NEW: Skip parent products that have variations (each variation is synced as a separate product)
        $hasVariations = $db->table('product_variations')->where('product_id', $productId)->where('is_deleted', 0)->countAllResults() > 0;
        if ($hasVariations) {
            log_message('info', 'EasyEcom: [UPDATE_PRODUCT] entity_id=' . $productId . ' reason=skipping parent product update, has variations');
            return ['success' => true, 'message' => 'Skipping parent product update as it has variations', 'data' => []];
        }

        $apiPayload = EasyEcomPayloadBuilder::buildUpdateProductPayload($product['easyecom_product_id'], $product);
        $response = $this->client->updateMasterProduct($apiPayload);

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [UPDATE_PRODUCT] entity_type=product_update entity_id=' . $productId . ' status=FAILED message=' . $errorMsg);
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }

        log_message('info', 'EasyEcom: [UPDATE_PRODUCT] entity_type=product_update entity_id=' . $productId . ' easyecom_id=' . $product['easyecom_product_id'] . ' status=SUCCESS');
        return ['success' => true, 'message' => 'Product updated', 'data' => []];
    }

    // -----------------------------------------------------------------------
    // Variation sync
    // -----------------------------------------------------------------------

    public function syncVariation(int $variationId, array $payload = []): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $productId = (int) ($payload['product_id'] ?? 0);
        $db = \Config\Database::connect();
        $existing = $db->table('product_variations')->select('easyecom_product_id, product_id')->where('id', $variationId)->get()->getRowArray();
        
        if ($existing && !empty($existing['easyecom_product_id'])) {
            log_message('info', 'EasyEcom: [SYNC_VARIATION] entity_id=' . $variationId . ' found easyecom_id=' . $existing['easyecom_product_id'] . ', proceeding with update');
            $variationDetails = $this->productModel->getProductVariationDetails($variationId);
            if ($variationDetails) {
                $pId = (int)($existing['product_id'] ?: $variationDetails['product_id']);
                $productDetails = $this->productModel->getProductDetails($pId);
                if ($productDetails) {
                    $imageUrl = $this->resolveVariationImageUrl($variationId, $pId);
                    $payload = EasyEcomPayloadBuilder::buildUpdateVariationPayload($existing['easyecom_product_id'], $productDetails, $variationDetails, $imageUrl);
                    $response = $this->client->updateMasterProduct($payload);
                    if (!EasyEcomClient::isApiFailure($response)) {
                        return ['success' => true, 'message' => 'Variation details updated', 'data' => ['easyecom_product_id' => $existing['easyecom_product_id']]];
                    }
                    return ['success' => false, 'message' => EasyEcomClient::extractErrorMessage($response), 'data' => $response];
                }
            }
            return ['success' => true, 'message' => 'Variation already synced, update skipped (no details)', 'data' => ['easyecom_product_id' => $existing['easyecom_product_id']]];
        }

        if ($productId <= 0) {
            $productId = (int) ($existing['product_id'] ?? 0);
        }

        if ($productId <= 0) {
            return ['success' => false, 'message' => 'Missing product_id in payload', 'data' => []];
        }

        $product = $this->productModel->getProductDetails($productId);
        $variation = $this->productModel->getProductVariationDetails($variationId);
        if (!$product || !$variation) {
            return ['success' => false, 'message' => 'Product or variation not found', 'data' => []];
        }

        $sku = trim((string) ($variation['sku'] ?? ''));
        if ($sku === '') {
            return ['success' => true, 'message' => 'Variation has no SKU, skipping', 'data' => []];
        }

        $imageUrl = $this->resolveVariationImageUrl($variationId, $productId);
        $apiPayload = EasyEcomPayloadBuilder::buildCreateVariationPayload($product, $variation, $imageUrl);
        $response = $this->client->createMasterProduct($apiPayload);

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [SYNC_VARIATION] entity_type=variation entity_id=' . $variationId . ' status=FAILED message=' . $errorMsg);
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }

        $eeId = $response['product_id'] ?? $response['ProductId'] ?? $response['data']['product_id'] ?? $response['data']['ProductId'] ?? $response['id'] ?? null;
        if ($eeId !== null && $eeId !== '') {
            $db = \Config\Database::connect();
            $db->table('product_variations')->where('id', $variationId)->update(['easyecom_product_id' => (string) $eeId]);
            log_message('info', 'EasyEcom: [SYNC_VARIATION] entity_type=variation entity_id=' . $variationId . ' easyecom_id=' . $eeId . ' status=SUCCESS');
            return ['success' => true, 'message' => 'Variation synced', 'data' => ['easyecom_product_id' => $eeId]];
        }

        return ['success' => true, 'message' => 'Variation created but no ID returned', 'data' => $response];
    }

    public function updateVariation(int $variationId, array $payload = []): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $productId = (int) ($payload['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'message' => 'Missing product_id in payload', 'data' => []];
        }

        $product = $this->productModel->getProductDetails($productId);
        $variation = $this->productModel->getProductVariationDetails($variationId);
        if (!$product || !$variation) {
            return ['success' => false, 'message' => 'Product or variation not found', 'data' => []];
        }

        $sku = trim((string) ($variation['sku'] ?? ''));
        if ($sku === '') {
            return ['success' => true, 'message' => 'Variation has no SKU, skipping', 'data' => []];
        }

        $eeId = isset($variation['easyecom_product_id']) ? trim((string) $variation['easyecom_product_id']) : '';

        // If not yet synced, create instead of update
        if ($eeId === '') {
            return $this->syncVariation($variationId, $payload);
        }

        $variationName = trim((string) ($variation['variation_name'] ?? ''));
        $productName = trim((string) ($product['product_name'] ?? ''));
        $name = $variationName !== '' ? $productName . ' - ' . $variationName : $productName;
        $price = (float) ($variation['price'] ?? 0);

        $apiPayload = [
            'hasProductCode' => 1,
            'RefProductCode' => $eeId,
            'product_name'   => $name,
            'sku'            => $sku,
            'mrp'            => $price,
            'selling_price'  => $price,
            'weight'         => (float) ($product['weight'] ?? 0),
        ];

        $response = $this->client->updateMasterProduct($apiPayload);

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [UPDATE_VARIATION] entity_type=variation_update entity_id=' . $variationId . ' status=FAILED message=' . $errorMsg);
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }

        log_message('info', 'EasyEcom: [UPDATE_VARIATION] entity_type=variation_update entity_id=' . $variationId . ' easyecom_id=' . $eeId . ' status=SUCCESS');
        return ['success' => true, 'message' => 'Variation updated', 'data' => []];
    }

    // -----------------------------------------------------------------------
    // Inventory sync
    // -----------------------------------------------------------------------

    /**
     * Sync product stock to EasyEcom via Inventory API (POST /inventory).
     * SKU is resolved to match the one used in CreateMasterProduct.
     *
     * @param int   $productId
     * @param array $payload  Optional: 'quantity' => int (default from product stock_quantity)
     */
    public function syncProductInventory(int $productId, array $payload = []): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $product = $this->productModel->getProductDetails($productId);
        if (empty($product)) {
            return ['success' => false, 'message' => 'Product not found', 'data' => []];
        }

        $quantity = max(0, (int) ($payload['quantity'] ?? $product['stock_quantity'] ?? 0));

        $internalSku = isset($product['sku_number']) ? trim((string) $product['sku_number']) : '';
        if ($internalSku === '') {
            return ['success' => true, 'message' => 'Product has no SKU, skipping inventory sync', 'data' => []];
        }

        $resolvedSku = $this->skuMapping->resolveForEasyEcom($internalSku);

        try {
            $response = $this->client->syncInventory($resolvedSku, $quantity);
            log_message('info', 'EasyEcom: [SYNC_INVENTORY] entity_type=stock entity_id=' . $productId . ' sku=' . $resolvedSku . ' quantity=' . $quantity . ' status=SUCCESS');
            return ['success' => true, 'message' => 'Inventory synced', 'data' => $response];
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [SYNC_INVENTORY] entity_type=stock entity_id=' . $productId . ' sku=' . $resolvedSku . ' quantity=' . $quantity . ' status=FAILED message=' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Sync variation stock to EasyEcom via Inventory API (POST /inventory).
     * SKU is resolved to match the one used in CreateMasterProduct for the variation.
     */
    public function syncVariationInventory(int $variationId, array $payload = []): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $variation = $this->productModel->getProductVariationDetails($variationId);
        if (empty($variation)) {
            return ['success' => false, 'message' => 'Variation not found', 'data' => []];
        }

        $quantity = max(0, (int) ($payload['quantity'] ?? $variation['stock_quantity'] ?? 0));

        $internalSku = isset($variation['sku']) ? trim((string) $variation['sku']) : '';
        if ($internalSku === '') {
            return ['success' => true, 'message' => 'Variation has no SKU, skipping inventory sync', 'data' => []];
        }

        $resolvedSku = $this->skuMapping->resolveForEasyEcom($internalSku);

        try {
            $response = $this->client->syncInventory($resolvedSku, $quantity);
            log_message('info', 'EasyEcom: [SYNC_INVENTORY] entity_type=variation_stock entity_id=' . $variationId . ' sku=' . $resolvedSku . ' quantity=' . $quantity . ' status=SUCCESS');
            return ['success' => true, 'message' => 'Variation inventory synced', 'data' => $response];
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [SYNC_INVENTORY] entity_type=variation_stock entity_id=' . $variationId . ' sku=' . $resolvedSku . ' quantity=' . $quantity . ' status=FAILED message=' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    // -----------------------------------------------------------------------
    // Order sync
    // -----------------------------------------------------------------------

    /**
     * Create order on EasyEcom from order data. Entry point for order integration.
     * Does not throw: on API failure logs and returns ['success' => false] so checkout is never broken.
     *
     * @param array $orderData Either ['order_no' => 'ORD0...'] or ['od' => orderRow, 'item' => itemsArray]
     * @return array ['success' => bool, 'message' => string, 'data' => [...]]
     */
    public function createEasyEcomOrder(array $orderData): array
    {
        $orderNo = $orderData['order_no'] ?? '';
        if ($orderNo !== '') {
            log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 1 started order_no=' . $orderNo);
        } else {
            log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 1 started with full order data');
        }

        if (! $this->client->isConfigured()) {
            log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 2 FAILED reason=EasyEcom not configured');
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }
        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 2 passed client is configured');

        $od   = $orderData['od'] ?? null;
        $item = $orderData['item'] ?? null;
        if ($od === null || $item === null) {
            if ($orderNo === '') {
                log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 3 FAILED reason=order_no or od/item required');
                return ['success' => false, 'message' => 'order_no or od/item required', 'data' => []];
            }
            $res = $this->orderModel->getOrderDetails($orderNo);
            $od   = $res['od'] ?? null;
            $item = $res['item'] ?? [];
            log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 3 fetched from DB order_no=' . $orderNo . ' found=' . ($od ? 'yes' : 'no') . ' items=' . count($item));
        } else {
            $orderNo = $orderNo ?: ($od['order_no'] ?? '');
            log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 3 using provided order data order_no=' . $orderNo . ' items=' . count($item));
        }

        if (empty($od) || ! is_array($item)) {
            log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 4 FAILED reason=Order not found or empty items');
            return ['success' => false, 'message' => 'Order not found in DB or empty items', 'data' => []];
        }

        $payload = $this->buildOrderPayload($od, $item, $this->client->getLocationKey());
        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 5 payload built order_no=' . ($od['order_no'] ?? '') . ' orderNumber=' . ($payload['orderNumber'] ?? '') . ' items_count=' . count($payload['items'] ?? []));

        if (empty($payload['items'])) {
            log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 6 FAILED reason=No valid line items (missing SKU)');
            return ['success' => false, 'message' => 'No valid line items (missing SKU)', 'data' => []];
        }

        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 7 calling Create Order API orderNumber=' . ($payload['orderNumber'] ?? ''));
        try {
            $response = $this->client->createOrder($payload);
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 8 FAILED exception order_no=' . ($od['order_no'] ?? '') . ' message=' . $e->getMessage());
            $this->saveOrderMappingOnFailure($od, 'failed');
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }

        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 8 API returned order_no=' . ($od['order_no'] ?? '') . ' response_keys=' . (is_array($response) ? implode(',', array_keys($response)) : gettype($response)));

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [CREATE_ORDER] STEP 9 FAILED API error order_no=' . ($od['order_no'] ?? '') . ' message=' . $errorMsg . ' response=' . json_encode($response));
            $this->saveOrderMappingOnFailure($od, 'failed');
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }

        $eeOrderId = $this->extractEasyEcomOrderId($response);
        if ($eeOrderId !== null && $eeOrderId !== '') {
            $eeOrderId = (string) $eeOrderId;
        } else {
            log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 8b order_id not in response — check EasyEcom docs. response=' . json_encode($response));
        }

        $localOrderId = (int) ($od['id'] ?? 0);
        $marketplace  = (string) (config(\Config\EasyEcom::class)->marketplaceId ?? '10');
        $this->orderMapping->saveMapping($localOrderId, $eeOrderId, $marketplace, 'created');
        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 10 mapping saved local_order_id=' . $localOrderId . ' easyecom_order_id=' . ($eeOrderId ?? ''));

        $updateData = ['easyecom_sync_status' => 'SYNCED'];
        if ($eeOrderId !== null && $eeOrderId !== '') {
            $updateData['easyecom_order_id'] = $eeOrderId;
        }
        $this->orderModel->update_order(['order_no' => $od['order_no']], $updateData);
        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 11 SUCCESS order_no=' . ($od['order_no'] ?? '') . ' easyecom_order_id=' . ($eeOrderId ?? '') . ' DB updated');

        // NEW: Sync variant inventory for all items in the order
        // This ensures the external EasyEcom stock levels match our deducted local stock.
        log_message('info', 'EasyEcom: [CREATE_ORDER] STEP 12 triggering variation inventory sync for order items');
        foreach ($item as $row) {
            $variationId = (int) ($row['variation_id'] ?? 0);
            if ($variationId > 0) {
                // Get fresh stock from DB (already deducted during create_order)
                $variation = $this->productModel->getProductVariationDetails($variationId);
                $newStock = (int) ($variation['stock_quantity'] ?? 0);
                
                log_message('info', 'EasyEcom: [CREATE_ORDER] variant_id=' . $variationId . ' new_stock=' . $newStock . ' triggering syncVariationInventory');
                $this->syncVariationInventory($variationId, ['quantity' => $newStock]);
            }
        }

        return ['success' => true, 'message' => 'Order created in EasyEcom', 'data' => ['easyecom_order_id' => $eeOrderId]];
    }

    /**
     * Extract EasyEcom order ID from Create Order API response.
     * API returns { "data": { "OrderID": "483144363", "SuborderID": "...", "InvoiceID": "..." } }.
     * Tries data.OrderID first, then common keys: order_id, orderId, data.order_id, etc.
     */
    private function extractEasyEcomOrderId(array $response): ?string
    {
        $candidates = [
            $response['data']['OrderID'] ?? null,
            $response['order_id'] ?? null,
            $response['orderId'] ?? null,
            $response['data']['order_id'] ?? null,
            $response['data']['orderId'] ?? null,
            $response['data']['id'] ?? null,
            $response['data']['reference_code'] ?? null,
            $response['data']['reference'] ?? null,
        ];
        $data = $response['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $candidates[] = $data;
        }
        if (is_numeric($data) && $data !== '') {
            $candidates[] = (string) $data;
        }
        foreach ($candidates as $v) {
            if ($v !== null && $v !== '' && (is_string($v) || is_numeric($v))) {
                return (string) $v;
            }
        }
        return null;
    }

    private function saveOrderMappingOnFailure(array $od, string $status): void
    {
        $localOrderId = (int) ($od['id'] ?? 0);
        $orderNo = $od['order_no'] ?? '';
        
        if ($localOrderId <= 0 && $orderNo === '') {
            return;
        }

        try {
            // Update mapping table
            if ($localOrderId > 0) {
                $this->orderMapping->saveMapping($localOrderId, null, (string) (config(\Config\EasyEcom::class)->marketplaceId ?? '10'), $status);
            }

            // NEW: Update main orders table status for retry visibility
            if ($orderNo !== '') {
                $this->orderModel->update_order(['order_no' => $orderNo], ['easyecom_sync_status' => 'FAILED']);
                log_message('info', 'EasyEcom: [CREATE_ORDER] marked orders.easyecom_sync_status as FAILED for order_no=' . $orderNo);
            }
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [CREATE_ORDER] saveMapping on failure: ' . $e->getMessage());
        }
    }

    public function syncOrder(string $orderNo): array
    {
        $result = $this->createEasyEcomOrder(['order_no' => $orderNo]);
        if ($result['success'] && ! empty($result['data']['easyecom_order_id'])) {
            $this->orderModel->update_order(['order_no' => $orderNo], ['status' => 'CONFIRMED']);
        }
        return $result;
    }

    /**
     * Cancel order on EasyEcom by local reference_code (order_no).
     * Call this after updating local order status to "cancelled".
     * On API success: saves cancellation in order mapping; on API failure: logs error, local cancellation is kept.
     *
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function cancelEasyEcomOrder(string $referenceCode): array
    {
        log_message('info', 'EasyEcom: [CancelOrder] STEP 1 started reference_code=' . $referenceCode);

        if (! $this->client->isConfigured()) {
            log_message('error', 'EasyEcom: [CancelOrder] STEP 2 FAILED reason=EasyEcom not configured');
            return ['success' => false, 'message' => 'EasyEcom not configured', 'data' => []];
        }

        $referenceCode = trim($referenceCode);
        if ($referenceCode === '') {
            log_message('error', 'EasyEcom: [CancelOrder] STEP 2 FAILED reason=reference_code is empty');
            return ['success' => false, 'message' => 'reference_code is empty', 'data' => []];
        }

        log_message('info', 'EasyEcom: [CancelOrder] calling EasyEcom cancelOrder API');
        try {
            $response = $this->client->cancelOrder($referenceCode);
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [CancelOrder] API exception reference_code=' . $referenceCode . ' message=' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }

        $status = $response['_status'] ?? 'unknown';
        log_message('info', 'EasyEcom: [CancelOrder] response received status=' . $status);

        if (EasyEcomClient::isApiFailure($response)) {
            $errorMsg = EasyEcomClient::extractErrorMessage($response);
            log_message('error', 'EasyEcom: [CancelOrder] API failed reference_code=' . $referenceCode . ' message=' . $errorMsg . ' response=' . json_encode($response));
            return ['success' => false, 'message' => $errorMsg, 'data' => $response];
        }

        // Success: save cancellation in DB (order mapping + optional order sync status)
        $res = $this->orderModel->getOrderDetails($referenceCode);
        $od = $res['od'] ?? null;
        if (! empty($od['id'])) {
            $localOrderId = (int) $od['id'];
            $marketplace   = (string) (config(\Config\EasyEcom::class)->marketplaceId ?? '10');
            $eeOrderId     = $this->orderMapping->getEasyEcomOrderId($localOrderId);
            $this->orderMapping->saveMapping($localOrderId, $eeOrderId, $marketplace, 'cancelled');
            $this->orderModel->update_order(['order_no' => $referenceCode], ['easyecom_sync_status' => 'CANCELLED']);
            log_message('info', 'EasyEcom: [CancelOrder] cancellation saved in DB local_order_id=' . $localOrderId);

            // If order had AWB, cancel shipment with carrier (EasyEcom/Delhivery)
            $awb = trim((string) ($od['awb_number'] ?? ''));
            if ($awb !== '' && $this->carrierService->isConfigured()) {
                $cancelResult = $this->carrierService->cancelShipment($awb);
                if (! $cancelResult['success']) {
                    log_message('warning', 'EasyEcom: [CancelOrder] carrier cancelShipment failed awb=' . $awb . ' message=' . ($cancelResult['message'] ?? ''));
                }
            }
        }

        log_message('info', 'EasyEcom: [CancelOrder] SUCCESS reference_code=' . $referenceCode);
        return ['success' => true, 'message' => 'Order cancelled on EasyEcom', 'data' => $response];
    }

    /**
     * Cancel order on EasyEcom (alias for cancelEasyEcomOrder for backward compatibility).
     */
    public function cancelOrder(string $orderNo): array
    {
        return $this->cancelEasyEcomOrder($orderNo);
    }

    // -----------------------------------------------------------------------
    // Shipment creation (Carrier Outbound API)
    // -----------------------------------------------------------------------

    /**
     * Create shipment via EasyEcom Carrier API (e.g. Delhivery).
     * Called when order is confirmed (webhook UpdateOrderV2 with order_status = Confirmed).
     * Saves awb_number, courier_name, tracking_url, shipment_status on success.
     * Does not throw; returns ['success' => false] on failure so order pipeline is not broken.
     *
     * @param string $referenceCode Order number (order_no)
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function createEasyEcomShipment(string $referenceCode): array
    {
        $referenceCode = trim($referenceCode);
        log_message('info', 'EasyEcom: [CREATE_SHIPMENT] STEP 1 started reference_code=' . $referenceCode);

        if (! $this->carrierService->isConfigured()) {
            log_message('error', 'EasyEcom: [CREATE_SHIPMENT] STEP 2 FAILED reason=Carrier not configured');
            return ['success' => false, 'message' => 'Carrier not configured', 'data' => []];
        }
        log_message('info', 'EasyEcom: [CREATE_SHIPMENT] STEP 2 passed carrier is configured');

        $res = $this->orderModel->getOrderDetails($referenceCode);
        $od   = $res['od'] ?? null;
        $item = $res['item'] ?? [];
        if (empty($od) || ! is_array($item)) {
            log_message('error', 'EasyEcom: [CREATE_SHIPMENT] STEP 3 FAILED reason=Order not found or no items reference_code=' . $referenceCode);
            return ['success' => false, 'message' => 'Order not found or no items', 'data' => []];
        }

        $localOrderId = (int) ($od['id'] ?? 0);
        if ($localOrderId <= 0) {
            log_message('error', 'EasyEcom: [CREATE_SHIPMENT] STEP 3 FAILED reason=Invalid order id reference_code=' . $referenceCode);
            return ['success' => false, 'message' => 'Invalid order', 'data' => []];
        }

        $easyecomOrderId = $this->orderMapping->getEasyEcomOrderId($localOrderId);
        if ($easyecomOrderId === null || $easyecomOrderId === '') {
            log_message('error', 'EasyEcom: [CREATE_SHIPMENT] STEP 4 FAILED reason=Order not synced to EasyEcom reference_code=' . $referenceCode);
            return ['success' => false, 'message' => 'Order not synced to EasyEcom', 'data' => []];
        }

        $existingAwb = trim((string) ($od['awb_number'] ?? ''));
        if ($existingAwb !== '') {
            log_message('info', 'EasyEcom: [CREATE_SHIPMENT] STEP 4 skipped reference_code=' . $referenceCode . ' reason=already has AWB awb_number=' . $existingAwb);
            return ['success' => true, 'message' => 'Shipment already created', 'data' => ['awb_number' => $existingAwb]];
        }

        $orderData = $this->buildCarrierOrderData($od, $item, $easyecomOrderId);
        if (empty($orderData['order_items'] ?? [])) {
            log_message('error', 'EasyEcom: [CREATE_SHIPMENT] STEP 5 FAILED reason=No valid line items (SKU) reference_code=' . $referenceCode);
            return ['success' => false, 'message' => 'No valid line items (missing SKU)', 'data' => []];
        }

        $result = $this->carrierService->createShipment($orderData);
        $data = $result['data'] ?? [];
        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => [
                'awb_number'   => $data['awb_number'] ?? $data['tracking_id'] ?? '',
                'courier'      => $data['courier_name'] ?? '',
                'tracking_url' => $data['tracking_url'] ?? '',
            ],
        ];
    }

    /**
     * Build order_data structure for Carrier API createShipment (matches EasyEcom carrier payload format).
     */
    private function buildCarrierOrderData(array $od, array $items, string $easyecomOrderId): array
    {
        $cfg = config(\Config\EasyEcom::class);
        $orderDate = $od['created_at'] ?? $od['created_date'] ?? date('Y-m-d H:i:s');
        $tat = date('Y-m-d H:i:s', strtotime($orderDate) + 86400);

        $invoiceId = is_numeric($easyecomOrderId) ? (int) $easyecomOrderId : 0;
        $orderId   = is_numeric($easyecomOrderId) ? (int) $easyecomOrderId : 0;
        if ($orderId === 0 && $easyecomOrderId !== '') {
            $orderId = (int) preg_replace('/\D/', '', $easyecomOrderId) ?: 0;
        }
        if ($invoiceId === 0) {
            $invoiceId = $orderId;
        }

        $warehouseId = (int) $cfg->carrierWarehouseId ?: 0;
        $companyName = $cfg->carrierCompanyName !== '' ? $cfg->carrierCompanyName : 'Company';

        $packageWeight = max(1, (int) ($od['package_weight'] ?? 200));
        $packageHeight = max(1, (int) ($od['package_height'] ?? 3));
        $packageLength = max(1, (int) ($od['package_length'] ?? 3));
        $packageWidth  = max(1, (int) ($od['package_width'] ?? 3));

        $orderItems = [];
        $subOrderNum = 0;
        foreach ($items as $idx => $row) {
            $internalSku = $row['sku_number'] ?? $row['sku'] ?? '';
            if ($internalSku === '') {
                continue;
            }
            $eeSku = $this->skuMapping->resolveForEasyEcom($internalSku);
            $subOrderNum++;
            $itemQty = (int) ($row['qty'] ?? 1);
            $weight  = max(1, (int) ($row['weight'] ?? $packageWeight));
            $length  = max(1, (int) ($row['length'] ?? $packageLength));
            $width   = max(1, (int) ($row['width'] ?? $packageWidth));
            $height  = max(1, (int) ($row['height'] ?? $packageHeight));
            $orderItems[] = [
                'suborder_id'           => $idx + 1,
                'suborder_num'          => (string) ($warehouseId . $easyecomOrderId . $idx),
                'invoicecode'           => null,
                'item_collectable_amount' => 0,
                'shipment_type'         => 'SelfShip',
                'suborder_quantity'     => $itemQty,
                'item_quantity'        => $itemQty,
                'returned_quantity'    => 0,
                'cancelled_quantity'   => 0,
                'shipped_quantity'     => 0,
                'tax_type'              => 'GST',
                'product_id'            => 0,
                'company_product_id'   => 0,
                'sku'                   => $eeSku,
                'expiry_type'           => 0,
                'sku_type'              => 'Normal',
                'sub_product_count'     => 1,
                'marketplace_sku'       => $eeSku,
                'listing_ref_number'    => '-',
                'listing_id'            => '-',
                'productName'           => $row['product_name'] ?? '',
                'description'           => null,
                'category'              => '',
                'brand'                 => '',
                'brand_id'              => 0,
                'model_no'              => '',
                'product_tax_code'      => null,
                'ean'                   => '',
                'size'                  => 'NA',
                'cost'                  => (float) ($row['sale_price'] ?? 0),
                'mrp'                   => (float) ($row['mrp_price'] ?? $row['sale_price'] ?? 0),
                'weight'                => $weight,
                'length'                => $length,
                'width'                 => $width,
                'height'                => $height,
                'scheme_applied'        => 0,
                'custom_fields'         => [],
                'serials'                => [null],
                'tax_rate'              => 18,
                'selling_price'         => (string) ($row['sale_price'] ?? '0'),
                'breakup_types'         => ['Item Amount Excluding Tax' => 0, 'Item Amount IGST' => 0],
                'station_scanned_quantity' => 0,
                'batch_scanned_quantity'   => 0,
                'assigned_quantity'      => $itemQty,
            ];
        }

        $stateCode = $this->getStateCode($od['state'] ?? '');

        return [
            'invoice_id'               => $invoiceId,
            'order_id'                 => $orderId,
            'blockSplit'               => 0,
            'reference_code'           => $od['order_no'],
            'company_name'             => $companyName,
            'warehouse_id'             => $warehouseId,
            'seller_gst'               => '',
            'assigned_company_name'    => $companyName,
            'assigned_warehouse_id'    => $warehouseId,
            'assigned_company_gst'     => '',
            'warehouse_contact'        => $cfg->carrierPickupContact,
            'pickup_address'           => $cfg->carrierPickupAddress,
            'pickup_city'              => $cfg->carrierPickupCity,
            'pickup_state'             => $cfg->carrierPickupState,
            'pickup_state_code'        => $cfg->carrierPickupStateCode,
            'pickup_pin_code'           => $cfg->carrierPickupPinCode,
            'pickup_country'            => $cfg->carrierPickupCountry,
            'invoice_currency_code'    => 'INR',
            'order_type'               => 'B2C',
            'order_type_key'           => 'retailorder',
            'replacement_order'         => 0,
            'marketplace'              => 'Offline',
            'MarketCId'                 => (int) ($cfg->marketplaceId ?? 10),
            'marketplace_id'            => (int) ($cfg->marketplaceId ?? 10),
            'market_shipped'           => 0,
            'merchant_c_id'            => (int) ($cfg->marketplaceId ?? 10),
            'qcPassed'                 => 1,
            'salesmanUserId'           => 0,
            'order_date'               => $orderDate,
            'tat'                      => $tat,
            'available_after'           => null,
            'invoice_date'             => '',
            'import_date'              => $orderDate,
            'last_update_date'         => $orderDate,
            'manifest_date'            => null,
            'manifest_no'               => null,
            'invoice_number'           => null,
            'marketplace_invoice_num'  => $od['order_no'],
            'shipping_last_update_date' => null,
            'batch_id'                 => 0,
            'batch_created_at'         => $orderDate,
            'message'                  => null,
            'courier_aggregator_name'  => null,
            'courier'                  => 'SelfShip',
            'carrier_id'               => 0,
            'awb_number'               => null,
            'Package Weight'           => $packageWeight,
            'Package Height'           => $packageHeight,
            'Package Length'           => $packageLength,
            'Package Width'            => $packageWidth,
            'order_status'             => 'Confirmed',
            'order_status_id'          => 2,
            'easyecom_order_history'    => null,
            'shipping_status'           => null,
            'shipping_status_id'       => null,
            'tracking_url'             => null,
            'shipping_history'          => null,
            'payment_mode'             => strtoupper($od['pay_mode'] ?? '') === 'COD' ? 'COD' : 'Online',
            'payment_mode_id'          => strtoupper($od['pay_mode'] ?? '') === 'COD' ? 2 : 1,
            'payment_gateway_transaction_number' => null,
            'buyer_gst'                => 'NA',
            'customer_name'            => $od['fullname'] ?? '',
            'shipping_name'            => $od['fullname'] ?? '',
            'contact_num'              => $od['mobile'] ?? '',
            'address_line_1'           => $od['address1'] ?? '',
            'address_line_2'           => $od['address2'] ?? null,
            'city'                     => $od['city'] ?? '',
            'pin_code'                 => $od['pincode'] ?? '',
            'state'                    => $od['state'] ?? '',
            'state_code'               => $stateCode,
            'country'                  => 'India',
            'country_code'             => 0,
            'email'                    => $od['email'] ?? '',
            'latitude'                 => null,
            'longitude'                => null,
            'billing_name'             => $od['fullname'] ?? '',
            'billing_address_1'        => $od['address1'] ?? '',
            'billing_address_2'         => $od['address2'] ?? null,
            'billing_city'             => $od['city'] ?? '',
            'billing_state'            => $od['state'] ?? '',
            'billing_state_code'       => $stateCode,
            'billing_pin_code'         => $od['pincode'] ?? '',
            'billing_country'          => 'India',
            'billing_mobile'           => $od['mobile'] ?? '',
            'order_quantity'           => array_sum(array_column($orderItems, 'item_quantity')),
            'documents'                => null,
            'invoice_documents'        => null,
            'collectable_amount'       => 0,
            'total_amount'             => (float) ($od['total_amount'] ?? 0),
            'total_tax'                 => 0,
            'breakup_types'            => ['Item Amount Excluding Tax' => 0, 'Item Amount IGST' => 0],
            'tcs_rate'                 => 0,
            'tcs_amount'                => 0,
            'customer_code'           => 'NA',
            'order_items'              => $orderItems,
        ];
    }

    private function getStateCode(string $state): string
    {
        $state = trim(strtolower($state));
        if ($state === '') {
            return '';
        }
        
        $codes = [
            'andhra pradesh' => '28', 'arunachal pradesh' => '12', 'assam' => '18', 'bihar' => '10',
            'chhattisgarh' => '22', 'goa' => '30', 'gujarat' => '24', 'haryana' => '06',
            'himachal pradesh' => '02', 'jharkhand' => '20', 'karnataka' => '29', 'kerala' => '32',
            'madhya pradesh' => '23', 'maharashtra' => '27', 'manipur' => '14', 'meghalaya' => '17',
            'mizoram' => '15', 'nagaland' => '13', 'odisha' => '21', 'punjab' => '03',
            'rajasthan' => '08', 'sikkim' => '11', 'tamil nadu' => '33', 'telangana' => '36',
            'tripura' => '16', 'uttar pradesh' => '09', 'uttarakhand' => '05', 'west bengal' => '19',
            'andaman and nicobar islands' => '35', 'chandigarh' => '04', 'dadra and nagar haveli and daman and diu' => '26',
            'delhi' => '07', 'jammu and kashmir' => '01', 'ladakh' => '38', 'lakshadweep' => '31', 'puducherry' => '34',
            // Common abbreviations
            'mh' => '27', 'dl' => '07', 'ka' => '29', 'tn' => '33', 'up' => '09', 'wb' => '19', 'ts' => '36', 'ap' => '28', 'gj' => '24'
        ];
        
        return $codes[$state] ?? '';
    }

    private function extractString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $val = $payload[$key] ?? null;
            if ($val !== null && $val !== '') {
                return is_string($val) ? trim($val) : (string) $val;
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach ($keys as $key) {
                $val = $data[$key] ?? null;
                if ($val !== null && $val !== '') {
                    return is_string($val) ? trim($val) : (string) $val;
                }
            }
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Trigger virtual inventory sync after a successful Create Master Product.
     * Flow: Create Product → Sync Product to EasyEcom → Update Virtual Inventory.
     * Only runs when product creation has succeeded. On failure, logs only;
     * does not throw or affect the product-creation response.
     */
    private function triggerPostCreationInventorySync(int $productId, array $product): void
    {
        $quantity = max(0, (int) ($product['stock_quantity'] ?? 0));
        try {
            $result = $this->syncProductInventory($productId, ['quantity' => $quantity]);
            if ($result['success']) {
                log_message('info', 'EasyEcom: [SYNC_PRODUCT] Post-creation virtual inventory sync OK entity_id=' . $productId . ' quantity=' . $quantity);
            } else {
                log_message('error', 'EasyEcom: [SYNC_PRODUCT] Post-creation virtual inventory sync failed entity_id=' . $productId . ' message=' . ($result['message'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [SYNC_PRODUCT] Post-creation virtual inventory sync exception entity_id=' . $productId . ' message=' . $e->getMessage());
        }
    }

    private function buildOrderPayload(array $od, array $items, string $locationKey): array
    {
        $easyEcomConfig = config(\Config\EasyEcom::class);
        $orderDate = $od['created_at'] ?? $od['created_date'] ?? date('Y-m-d H:i:s');
        $expDelivery = date('Y-m-d H:i:s', strtotime($orderDate) + 86400);

        $apiItems = [];
        foreach ($items as $idx => $row) {
            $internalSku = $row['sku_number'] ?? $row['sku'] ?? '';
            if ($internalSku === '') {
                continue;
            }
            $orderItemId = ($od['order_no'] ?? 'ORD') . '-' . ($row['id'] ?? $idx);
            
            $qty = (int) ($row['qty'] ?? 1);
            $gst_rate = (float) ($row['gst_rate'] ?? 0);
            $sale_price = (float) ($row['sale_price'] ?? 0);
            $unit_tax = ($sale_price * $gst_rate / 100);

            $apiItems[] = [
                'OrderItemId'   => (string) $orderItemId,
                'Sku'           => $this->skuMapping->resolveForEasyEcom($internalSku),
                'AccountingSku' => $row['accounting_sku'] ?? $row['fsn'] ?? '',
                'ean'           => $row['ean'] ?? '',
                'productName'   => $row['product_name'] ?? '',
                'Quantity'      => $qty,
                'Price'         => $sale_price,
                'TaxRate'       => $gst_rate,
                'TaxAmount'     => round($unit_tax, 2),
                'itemDiscount'  => (float) ($row['discount_amount'] ?? 0),
                'custom_fields' => [],
            ];
        }

        // Prepare customer and address data for the payload
        $fullName = $od['fullname'] ?? '';
        $address = [
            'name'         => $fullName,
            'addressLine1' => $od['address1'] ?? '',
            'addressLine2' => $od['address2'] ?? '',
            'postalCode'   => $od['pincode'] ?? '',
            'city'         => $od['city'] ?? '',
            'state'        => $od['state'] ?? '',
            'country'      => 'India',
            'contact'      => $od['mobile'] ?? '',
            'email'        => $od['email'] ?? '',
        ];

        $shipping = array_merge($address, ['latitude' => '', 'longitude' => '']);
        
        $customer = [
            [
                'billing'  => $address,
                'shipping' => $shipping,
            ]
        ];

        $payMode = strtoupper((string) ($od['pay_mode'] ?? ''));
        
        return [
            'orderType'              => 'retailorder',
            'marketplaceId'          => (int) ($easyEcomConfig->marketplaceId ?? 10),
            'orderNumber'            => $od['order_no'],
            'orderDate'              => $orderDate,
            'expDeliveryDate'        => $expDelivery,
            'remarks1'               => $od['remarks'] ?? '',
            'remarks2'               => '',
            'shippingCost'           => (float) ($od['shipping_amount'] ?? 0),
            'discount'               => (float) ($od['discount_amount'] ?? 0),
            'walletDiscount'         => (float) ($od['wallet_amount'] ?? 0),
            'promoCodeDiscount'      => (float) ($od['promo_amount'] ?? 0),
            'prepaidDiscount'        => 0,
            'paymentMode'            => $payMode === 'COD' ? 2 : 5,
            'paymentGateway'         => $od['pay_gateway_name'] ?? '',
            'shippingMethod'         => $payMode === 'COD' ? 1 : 3,
            'is_market_shipped'      => 0,
            'cp_auto_create'         => 1,
            'paymentTransactionNumber' => $od['txn_id'] ?? $od['order_no'] ?? '',
            'packageWeight'          => max(1, (int) ($od['package_weight'] ?? 1)),
            'packageHeight'          => max(1, (int) ($od['package_height'] ?? 1)),
            'packageWidth'           => max(1, (int) ($od['package_width'] ?? 1)),
            'packageLength'          => max(1, (int) ($od['package_length'] ?? 1)),
            'items'                  => $apiItems,
            'customer'               => $customer,
        ];
    }

    /**
     * Confirm order on EasyEcom to move it from Pending to Confirmed.
     */
    public function confirmEasyEcomOrder(string $orderId, array $dimensions = []): array
    {
        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured'];
        }
        try {
            $response = $this->client->confirmOrder($orderId, $dimensions);
            return ['success' => !EasyEcomClient::isApiFailure($response), 'message' => 'Status update triggered', 'data' => $response];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update order address on EasyEcom.
     */
    public function updateEasyEcomOrderAddress(array $data): array
    {
        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'EasyEcom not configured'];
        }
        try {
            $response = $this->client->updateOrderAddress($data);
            return ['success' => !EasyEcomClient::isApiFailure($response), 'message' => 'Address update triggered', 'data' => $response];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function resolveProductDescription(int $productId): string
    {
        $descriptions = $this->productModel->getAllProductDescriptions($productId);
        $description = '';
        foreach ($descriptions as $d) {
            if (!empty($d['content'])) {
                $description = trim((string) $d['content']);
                if (($d['description_type'] ?? '') === 'long') {
                    break;
                }
            }
        }
        if ($description === '' && $descriptions) {
            $description = trim((string) ($descriptions[0]['content'] ?? ''));
        }
        return $description;
    }

    private function resolveVariationImageUrl(int $variationId, int $productId): string
    {
        // 1. Try to get variation-specific image
        $vImages = $this->productModel->getVariationImages($variationId);
        if (!empty($vImages) && !empty($vImages[0]['image'])) {
            return trim((string) $vImages[0]['image']);
        }

        // 2. Fallback to parent product base image
        return $this->resolveProductImageUrl($productId);
    }

    private function resolveProductImageUrl(int $productId): string
    {
        $images = $this->productModel->getProductImageDetailsOrdered($productId);
        if (!empty($images) && !empty($images[0]['image'])) {
            return trim((string) $images[0]['image']);
        }
        return '';
    }
}
