<?php

namespace App\Services;

use App\Models\OrderModel;

/**
 * DeliveryTrackingService — Phase 2 (Delhivery via EasyEcom)
 *
 * Handles all inbound shipment and tracking data from EasyEcom webhooks.
 * Deliberately does NOT touch product/inventory logic (Phase 4 Safety).
 *
 * Enable/disable via .env:
 *   DELIVERY_TRACKING_ENABLED = true
 */
class DeliveryTrackingService
{
    private OrderModel $orderModel;

    /** @var bool Feature flag — disable to suppress all DB writes */
    private bool $enabled;

    public function __construct(?OrderModel $orderModel = null)
    {
        $this->orderModel = $orderModel ?? new OrderModel();
        $this->enabled    = filter_var(env('DELIVERY_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Process an EasyEcom "Manifested" webhook payload.
     *
     * Captures: AWB number, courier name, label URL, shipment status.
     * Sets order status to MANIFESTED and records shipped_at timestamp.
     *
     * EasyEcom V2 manifested payload example:
     * {
     *   "orders": [
     *     {
     *       "reference_code": "ORD0001",
     *       "order_status":   "Shipped",
     *       "awb_number":     "1234567890",
     *       "manifest_no":    "MAN001",
     *       "shipping_partner": "Delhivery",
     *       "documents":      [{ "type": "label", "url": "https://..." }]
     *     }
     *   ]
     * }
     *
     * @return array{updated: int, failed: list<string>, disabled: bool}
     */
    public function processManifested(array $payload): array
    {
        if (! $this->enabled) {
            log_message('info', 'DeliveryTracking: [MANIFESTED] feature disabled (DELIVERY_TRACKING_ENABLED=false)');
            return ['updated' => 0, 'failed' => [], 'disabled' => true];
        }

        $orders  = $this->extractOrderList($payload);
        $updated = 0;
        $failed  = [];

        foreach ($orders as $order) {
            $ref = $this->extractRef($order);
            if ($ref === '') {
                $failed[] = 'unknown (no reference_code in payload)';
                continue;
            }

            $data = $this->buildManifestedUpdateData($order);
            $ok   = $this->orderModel->update_order(['order_no' => $ref], $data);

            if ($ok) {
                $updated++;
                log_message('info', 'DeliveryTracking: [MANIFESTED] order=' . $ref
                    . ' awb=' . ($data['awb_number'] ?? '-')
                    . ' courier=' . ($data['courier_name'] ?? '-')
                    . ' status=' . ($data['status'] ?? '-'));
            } else {
                $failed[] = $ref;
                log_message('error', 'DeliveryTracking: [MANIFESTED] order=' . $ref . ' DB update failed');
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'disabled' => false];
    }

    /**
     * Process an EasyEcom tracking/shipping-status webhook payload.
     *
     * Maps courier status to internal order status, records timestamps.
     * Supports: SHIPPED → IN_TRANSIT, DELIVERED, RTO_DELIVERED.
     *
     * EasyEcom tracking payload (root array or orders[] wrapper):
     * [
     *   {
     *     "reference_code":          "ORD0001",
     *     "awbNumber":               "1234567890",
     *     "currentShippingStatus":   "Delivered",
     *     "orderStatus":             "Delivered",
     *     "shipping_partner":        "Delhivery",
     *     "courier":                 "Delhivery"
     *   }
     * ]
     *
     * @return array{updated: int, failed: list<string>, disabled: bool}
     */
    public function processTracking(array $payload): array
    {
        if (! $this->enabled) {
            log_message('info', 'DeliveryTracking: [TRACKING] feature disabled (DELIVERY_TRACKING_ENABLED=false)');
            return ['updated' => 0, 'failed' => [], 'disabled' => true];
        }

        // Normalise root-array format to orders[] wrapper
        if (array_is_list($payload) && ! empty($payload)) {
            $payload = ['orders' => $payload];
        }

        $orders  = $this->extractOrderList($payload);
        $updated = 0;
        $failed  = [];

        foreach ($orders as $order) {
            $ref = $this->extractRef($order);
            if ($ref === '') {
                $failed[] = 'unknown';
                continue;
            }

            $data = $this->buildTrackingUpdateData($order);
            if (empty($data)) {
                log_message('info', 'DeliveryTracking: [TRACKING] order=' . $ref . ' – no mappable status, skip');
                continue;
            }

            $ok = $this->orderModel->update_order(['order_no' => $ref], $data);
            if ($ok) {
                $updated++;
                log_message('info', 'DeliveryTracking: [TRACKING] order=' . $ref
                    . ' status=' . ($data['status'] ?? '-')
                    . ' shipment_status=' . ($data['shipment_status'] ?? '-'));
            } else {
                $failed[] = $ref;
                log_message('error', 'DeliveryTracking: [TRACKING] order=' . $ref . ' DB update failed');
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'disabled' => false];
    }

    /**
     * Return current shipment/delivery data for an order.
     *
     * @return array|null  Order row with delivery columns, or null if not found.
     */
    public function getShipmentData(string $orderNo): ?array
    {
        $res = $this->orderModel->getOrderDetails($orderNo);
        if (empty($res['od'])) {
            return null;
        }
        $od = $res['od'];
        return [
            'order_no'          => $od['order_no'] ?? $orderNo,
            'status'            => $od['status'] ?? null,
            'awb_number'        => $od['awb_number'] ?? null,
            'courier_name'      => $od['courier_name'] ?? null,
            'shipment_status'   => $od['shipment_status'] ?? null,
            'fulfillment_status'=> $od['fulfillment_status'] ?? null,
            'label_url'         => $od['label_url'] ?? null,
            'easyecom_order_id' => $od['easyecom_order_id'] ?? null,
            'shipped_at'        => $od['shipped_at'] ?? null,
            'delivered_at'      => $od['delivered_at'] ?? null,
            'rto_at'            => $od['rto_at'] ?? null,
            'cancelled_at'      => $od['cancelled_at'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers — data extraction
    // -----------------------------------------------------------------------

    /**
     * Extract the order list from the payload.
     * Supports: { orders: [...] } and top-level single-order objects.
     *
     * @return list<array>
     */
    private function extractOrderList(array $payload): array
    {
        if (isset($payload['orders']) && is_array($payload['orders'])) {
            return $payload['orders'];
        }
        // Root-level single object: check for reference_code key
        $ref = $payload['reference_code'] ?? $payload['order_no'] ?? $payload['reference_id'] ?? null;
        if ($ref !== null && $ref !== '') {
            return [$payload];
        }
        return [];
    }

    /**
     * Extract the order reference code from a single order array.
     */
    private function extractRef(array $order): string
    {
        foreach (['reference_code', 'order_no', 'order_reference', 'channel_order_id', 'reference_id'] as $key) {
            $val = $order[$key] ?? null;
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        return '';
    }

    /**
     * Build DB update array for a Manifested webhook order entry.
     * Fields written: status, awb_number, courier_name, label_url, shipment_status,
     *                  fulfillment_status, easyecom_order_id, shipped_at.
     */
    private function buildManifestedUpdateData(array $order): array
    {
        $data = [];

        // Order and shipment status
        $data['status']           = 'MANIFESTED';
        $data['shipment_status']  = 'MANIFESTED';
        $data['fulfillment_status'] = 'FULFILLED';

        // AWB number (multiple possible keys from EasyEcom)
        $awb = $order['awb_number'] ?? $order['awbNumber'] ?? $order['tracking_number'] ?? $order['awb'] ?? null;
        if ($awb !== null && $awb !== '') {
            $data['awb_number'] = (string) $awb;
        }

        // Courier name (Delhivery or any other partner assigned by EasyEcom)
        $courier = $order['shipping_partner'] ?? $order['courierName'] ?? $order['courier_name']
            ?? $order['courier'] ?? $order['logistics_partner'] ?? null;
        if ($courier !== null && $courier !== '') {
            $data['courier_name'] = (string) $courier;
        }

        // Shipping label URL — EasyEcom sends in documents[] or as a direct field
        $labelUrl = $this->extractLabelUrl($order);
        if ($labelUrl !== null) {
            $data['label_url'] = $labelUrl;
        }

        // EasyEcom internal order ID
        $eeId = $order['order_id'] ?? $order['easyecom_order_id'] ?? null;
        if ($eeId !== null && $eeId !== '') {
            $data['easyecom_order_id'] = (string) $eeId;
        }

        // Timestamp
        $data['shipped_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * Build DB update array for a tracking webhook order entry.
     * Maps shipping status → internal status, records timestamps.
     */
    private function buildTrackingUpdateData(array $order): array
    {
        $rawStatus = $order['currentShippingStatus'] ?? $order['orderStatus']
            ?? $order['shipping_status'] ?? $order['order_status'] ?? $order['status'] ?? '';
        $internal = $this->mapShippingStatus((string) $rawStatus);

        if ($internal === '') {
            return [];
        }

        $data = [];
        $data['status']          = $internal;
        $data['shipment_status'] = (string) $rawStatus;

        // Update AWB if present
        $awb = $order['awbNumber'] ?? $order['awb_number'] ?? $order['tracking_number'] ?? null;
        if ($awb !== null && $awb !== '') {
            $data['awb_number'] = (string) $awb;
        }

        // Courier name
        $courier = $order['shipping_partner'] ?? $order['courierName'] ?? $order['courier_name']
            ?? $order['courier'] ?? null;
        if ($courier !== null && $courier !== '') {
            $data['courier_name'] = (string) $courier;
        }

        // Set timestamps based on final status
        $now = date('Y-m-d H:i:s');
        if ($internal === 'DELIVERED') {
            $data['delivered_at'] = $now;
        } elseif ($internal === 'RTO_DELIVERED') {
            $data['rto_at'] = $now;
        } elseif ($internal === 'CANCELLED') {
            $data['cancelled_at'] = $now;
        } elseif ($internal === 'IN_TRANSIT' && empty($data['shipped_at'])) {
            // Only set shipped_at if not already set; DB will preserve it on UPDATE
        }

        return $data;
    }

    /**
     * Extract shipping label URL from the EasyEcom payload.
     * EasyEcom can send it as: documents[].url (type=label), label_url, or shipping_label.
     */
    private function extractLabelUrl(array $order): ?string
    {
        // documents array: [{ "type": "label", "url": "https://..." }, ...]
        if (isset($order['documents']) && is_array($order['documents'])) {
            foreach ($order['documents'] as $doc) {
                if (is_array($doc)) {
                    $type = strtolower((string) ($doc['type'] ?? ''));
                    if (in_array($type, ['label', 'shipping_label', 'manifest'], true) && ! empty($doc['url'])) {
                        return (string) $doc['url'];
                    }
                }
            }
            // Fallback: first document with a URL
            foreach ($order['documents'] as $doc) {
                if (is_array($doc) && ! empty($doc['url'])) {
                    return (string) $doc['url'];
                }
            }
        }

        // Direct fields
        $url = $order['label_url'] ?? $order['shipping_label'] ?? $order['label'] ?? null;
        return ($url !== null && $url !== '') ? (string) $url : null;
    }

    /**
     * Map EasyEcom/Delhivery shipping status string → internal order status.
     *
     * Returns '' if the status is not actionable.
     */
    private function mapShippingStatus(string $status): string
    {
        if ($status === '') {
            return '';
        }
        $a = strtoupper(trim($status));

        if (str_contains($a, 'DELIVERED') && ! str_contains($a, 'RTO')) {
            return 'DELIVERED';
        }
        if (str_contains($a, 'RTO')) {
            return 'RTO_DELIVERED';
        }
        if (str_contains($a, 'CANCEL')) {
            return 'CANCELLED';
        }
        if (str_contains($a, 'TRANSIT')
            || $a === 'DISPATCHED'
            || str_contains($a, 'PICKUP')
            || str_contains($a, 'OUT FOR')
            || str_contains($a, 'SHIPPED')
            || str_contains($a, 'SHIPMENT')
        ) {
            return 'IN_TRANSIT';
        }
        if (str_contains($a, 'MANIFEST')) {
            return 'MANIFESTED';
        }
        if (str_contains($a, 'CONFIRM') || str_contains($a, 'CREATE')) {
            return 'CONFIRMED';
        }
        return '';
    }
}
