<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\EasyEcomSkuMappingModel;
use App\Services\DeliveryTrackingService;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * EasyEcom per-action webhook listeners (incoming from EasyEcom).
 *
 * - Point 5: Update Inventory – real-time stock updates (gated by INVENTORY_SYNC_ENABLED)
 * - Point 8: Confirm Order (V2), Manifested (V2) – order processing status
 * - Point 9: Order Tracking – In Transit, Delivered, RTO Delivered
 *
 * Feature flags (.env):
 *   DELIVERY_TRACKING_ENABLED = true   (captures AWB/courier/timestamp)
 *   INVENTORY_SYNC_ENABLED    = false  (prevents product stock modification)
 */
class EasyEcomWebhooks extends BaseController
{
    use ResponseTrait;

    protected OrderModel $orderModel;
    protected ProductModel $productModel;
    protected EasyEcomSkuMappingModel $skuMapping;
    protected DeliveryTrackingService $deliveryService;

    private bool $inventorySyncEnabled;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->orderModel      = new OrderModel();
        $this->productModel    = new ProductModel();
        $this->skuMapping      = new EasyEcomSkuMappingModel();
        $this->deliveryService = new DeliveryTrackingService($this->orderModel);

        $this->inventorySyncEnabled = filter_var(
            env('INVENTORY_SYNC_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    // -----------------------------------------------------------------------
    // Point 5 — Inventory webhook
    // -----------------------------------------------------------------------

    /**
     * Update Inventory webhook (Point 5).
     * POST from EasyEcom with inventory payload; update local stock by SKU.
     * Gated by INVENTORY_SYNC_ENABLED.
     */
    public function inventory()
    {
        if (!$this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }

        $payload = $this->request->getJSON(true) ?: [];

        if (! $this->inventorySyncEnabled) {
            log_message('info', 'EasyEcom: [INVENTORY] sync disabled — payload acknowledged but not applied');
            return $this->respond([
                'success' => true,
                'message' => 'Received (inventory sync disabled)',
                'updated' => 0,
            ]);
        }

        log_message('info', 'EasyEcom: [INVENTORY] webhook received — normalizing payload');
        $items = $this->normalizeInventoryPayload($payload);

        $updated = 0;
        $failed  = [];

        foreach ($items as $idx => $item) {
            $easyecomSku = $item['sku'] ?? '';
            $quantity    = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($easyecomSku === '') {
                continue;
            }
            $internalSku = $this->skuMapping->resolveFromEasyEcom($easyecomSku);
            $result = $this->productModel->updateStockBySku($internalSku, $quantity);
            if ($result['updated']) {
                $updated++;
            } else {
                $failed[] = ['sku' => $internalSku, 'easyecom_sku' => $easyecomSku, 'reason' => $result['message']];
                log_message('error', 'EasyEcom: [INVENTORY] update failed sku=' . $easyecomSku . ': ' . ($result['message'] ?? ''));
            }
        }

        if (empty($items)) {
            return $this->respond(['success' => true, 'message' => 'Received', 'updated' => 0, 'note' => 'No SKU items in payload']);
        }

        log_message('info', 'EasyEcom: [INVENTORY] processed updated=' . $updated . ' failed=' . count($failed));
        return $this->respond([
            'success' => true,
            'message' => 'Processed',
            'updated' => $updated,
            'failed'  => $failed,
        ]);
    }

    // -----------------------------------------------------------------------
    // Point 8 — Confirm Order
    // -----------------------------------------------------------------------

    /**
     * Confirm Order (Point 8 – V2 format).
     */
    public function confirmOrder()
    {
        if (!$this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        $payload = $this->request->getJSON(true) ?: [];
        log_message('info', 'EasyEcom: [CONFIRM_ORDER] webhook received');
        $this->applyOrderStatusFromWebhook($payload, 'CONFIRMED');
        return $this->respond(['success' => true, 'message' => 'Received']);
    }

    // -----------------------------------------------------------------------
    // Point 8 — Manifested (Shipment Created)
    // -----------------------------------------------------------------------

    /**
     * Manifested (Point 8 – V2 format).
     * Captures AWB, courier, label via DeliveryTrackingService.
     */
    public function manifested()
    {
        if (!$this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        $payload = $this->request->getJSON(true) ?: [];
        log_message('info', 'EasyEcom: [MANIFESTED] webhook received');

        $result = $this->deliveryService->processManifested($payload);

        if ($result['disabled'] ?? false) {
            log_message('info', 'EasyEcom: [MANIFESTED] delivery tracking disabled, applying basic status');
            $this->applyOrderStatusFromWebhook($payload, 'MANIFESTED');
        }

        log_message('info', 'EasyEcom: [MANIFESTED] completed updated=' . ($result['updated'] ?? 0) . ' failed=' . count($result['failed'] ?? []));
        return $this->respond([
            'success' => true,
            'message' => 'Received',
            'updated' => $result['updated'] ?? 0,
        ]);
    }

    // -----------------------------------------------------------------------
    // Point 9 — Order Tracking
    // -----------------------------------------------------------------------

    /**
     * Order Tracking Action (Point 9 – Tracking Status Push).
     * Delegates to DeliveryTrackingService for timestamp recording.
     */
    public function tracking()
    {
        if (!$this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        $payload = $this->request->getJSON(true) ?: [];
        log_message('info', 'EasyEcom: [TRACKING] webhook received');

        $result = $this->deliveryService->processTracking($payload);

        if ($result['disabled'] ?? false) {
            log_message('info', 'EasyEcom: [TRACKING] delivery tracking disabled, applying legacy fallback');
            $this->legacyTrackingFallback($payload);
        }

        log_message('info', 'EasyEcom: [TRACKING] completed updated=' . ($result['updated'] ?? 0));
        return $this->respond(['success' => true, 'message' => 'Received']);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Normalize EasyEcom inventory payload to list of [sku, quantity].
     */
    private function normalizeInventoryPayload(array $payload): array
    {
        $out = [];
        $sku = $payload['sku'] ?? $payload['seller_sku'] ?? $payload['sku_code'] ?? null;
        $qty = $payload['quantity'] ?? $payload['available_quantity'] ?? $payload['stock'] ?? $payload['inventory'] ?? null;
        if ($sku !== null && $sku !== '') {
            $out[] = ['sku' => (string) $sku, 'quantity' => is_numeric($qty) ? (int) $qty : 0];
            return $out;
        }
        $list = $payload['inventoryData'] ?? $payload['items'] ?? $payload['skus'] ?? $payload['inventory'] ?? [];
        if (!is_array($list)) {
            return $out;
        }
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $s = $entry['sku'] ?? $entry['seller_sku'] ?? $entry['sku_code'] ?? '';
            $q = $entry['quantity'] ?? $entry['available_quantity'] ?? $entry['stock'] ?? $entry['inventory'] ?? 0;
            if ($s !== '') {
                $out[] = ['sku' => (string) $s, 'quantity' => is_numeric($q) ? (int) $q : 0];
            }
        }
        return $out;
    }

    /**
     * Update order status from webhook payload (simple status-only update).
     */
    private function applyOrderStatusFromWebhook(array $payload, string $status): void
    {
        $refs = $this->extractOrderReferencesFromPayload($payload);
        if (empty($refs)) {
            log_message('info', 'EasyEcom: [APPLY_STATUS] no order reference in payload for status=' . $status);
            return;
        }
        foreach ($refs as $item) {
            $orderRef = $item['reference_code'];
            $updated = $this->orderModel->update_order(['order_no' => $orderRef], ['status' => $status]);
            if ($updated) {
                log_message('info', 'EasyEcom: [APPLY_STATUS] order=' . $orderRef . ' → ' . $status);
            } else {
                log_message('error', 'EasyEcom: [APPLY_STATUS] order=' . $orderRef . ' not found or update failed');
            }
        }
    }

    /**
     * Legacy tracking fallback (used when DELIVERY_TRACKING_ENABLED=false).
     */
    private function legacyTrackingFallback(array $payload): void
    {
        if (array_is_list($payload) && ! empty($payload)) {
            $payload = ['orders' => $payload];
        }
        $refs   = $this->extractOrderReferencesFromPayload($payload);
        $status = $this->mapTrackingToStatus($payload);
        if ($status === null && ! empty($refs)) {
            $orderData = $refs[0]['order'] ?? [];
            $status = $this->mapTrackingToStatus($orderData);
        }
        if ($status !== null && ! empty($refs)) {
            foreach ($refs as $item) {
                $orderRef      = $item['reference_code'];
                $orderStatus   = $this->mapTrackingToStatus($item['order'] ?? []);
                $statusToApply = $orderStatus ?? $status;
                $this->orderModel->update_order(['order_no' => $orderRef], ['status' => $statusToApply]);
            }
        }
    }

    /**
     * Extract order references from webhook payload.
     *
     * @return list<array{reference_code: string, order?: array}>
     */
    private function extractOrderReferencesFromPayload(array $payload): array
    {
        $list = $payload['orders'] ?? null;
        if (is_array($list) && ! empty($list)) {
            $out = [];
            foreach ($list as $order) {
                if (! is_array($order)) {
                    continue;
                }
                $ref = $order['reference_code'] ?? $order['order_no'] ?? $order['order_reference'] ?? $order['channel_order_id'] ?? $order['reference_id'] ?? '';
                if ($ref !== '') {
                    $out[] = ['reference_code' => (string) $ref, 'order' => $order];
                }
            }
            return $out;
        }
        $ref = $payload['order_no'] ?? $payload['order_reference'] ?? $payload['reference_code'] ?? $payload['channel_order_id'] ?? $payload['reference_id'] ?? null;
        if ($ref !== null && $ref !== '') {
            return [['reference_code' => (string) $ref, 'order' => $payload]];
        }
        return [];
    }

    /**
     * Map EasyEcom tracking status to our order status (Point 9 legacy fallback).
     */
    private function mapTrackingToStatus(array $payload): ?string
    {
        $action = $payload['currentShippingStatus'] ?? $payload['orderStatus'] ?? $payload['action'] ?? $payload['order_status'] ?? $payload['status'] ?? $payload['tracking_status'] ?? '';
        $a = strtoupper((string) $action);
        if (str_contains($a, 'DELIVERED') && ! str_contains($a, 'RTO')) {
            return 'DELIVERED';
        }
        if (str_contains($a, 'RTO')) {
            return 'RTO_DELIVERED';
        }
        if (str_contains($a, 'TRANSIT') || $a === 'DISPATCHED') {
            return 'IN_TRANSIT';
        }
        if ($a === 'SHIPPED' || str_contains($a, 'SHIPMENT') || str_contains($a, 'PICKUP') || str_contains($a, 'OUT FOR')) {
            return 'IN_TRANSIT';
        }
        if ($a === 'MANIFESTED' || str_contains($a, 'MANIFEST')) {
            return 'MANIFESTED';
        }
        return null;
    }
}
