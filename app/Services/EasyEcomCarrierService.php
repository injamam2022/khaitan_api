<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\EasyEcomCarrierClient;
use App\Models\OrderModel;
use Config\EasyEcom as EasyEcomConfig;

/**
 * EasyEcom Carrier Outbound integration — production service layer.
 *
 * EasyEcom acts as middleware between this backend and the courier (e.g. Delhivery).
 * Carrier API collection: https://api-docs.easyecom.io/#72423b60-baae-42ab-a0bb-7ee9699ff035
 * This service handles: authenticateCarrier, listCarriers, createShipment, cancelShipment, authorizeTracking, updateTrackingStatus.
 *
 * Flow: Order Confirmed → authenticateCarrier() → createShipment() → AWB generated →
 *       authorizeTracking() → updateTrackingStatus() for status updates.
 */
class EasyEcomCarrierService
{
    private const LOG_PREFIX = 'EasyEcom: ';
    private const TRACKING_TOKEN_CACHE_KEY = 'easyecom_carrier_tracking_token';
    private const TRACKING_TOKEN_TTL = 3500; // ~1h, slightly under typical JWT expiry

    private EasyEcomCarrierClient $client;
    private OrderModel $orderModel;
    private EasyEcomConfig $config;

    public function __construct(
        ?EasyEcomCarrierClient $client = null,
        ?OrderModel $orderModel = null,
        ?EasyEcomConfig $config = null
    ) {
        $this->client     = $client ?? new EasyEcomCarrierClient();
        $this->orderModel = $orderModel ?? new OrderModel();
        $this->config     = $config ?? config(EasyEcomConfig::class);
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    // -----------------------------------------------------------------------
    // 1. Carrier Authentication
    // -----------------------------------------------------------------------

    /**
     * Authenticate with EasyEcom Carrier API.
     * Validates response code 200 and logs status.
     *
     * @return array{success: bool, message: string, response?: array}
     */
    public function authenticateCarrier(): array
    {
        log_message('info', self::LOG_PREFIX . '[CARRIER_AUTH] requesting authentication');

        if (! $this->client->isConfigured()) {
            log_message('error', self::LOG_PREFIX . '[CARRIER_AUTH] carrier not configured');
            return ['success' => false, 'message' => 'Carrier not configured'];
        }

        try {
            $response = $this->client->authenticate();
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[CARRIER_AUTH] exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $status = $response['_status'] ?? 0;
        if ($status === 200) {
            log_message('info', self::LOG_PREFIX . '[CARRIER_AUTH] success response=200');
            return ['success' => true, 'message' => 'OK', 'response' => $response];
        }

        $msg = $response['message'] ?? $response['error'] ?? 'HTTP ' . $status;
        log_message('error', self::LOG_PREFIX . '[CARRIER_AUTH] failed response=' . $status . ' message=' . $msg);
        return ['success' => false, 'message' => (string) $msg, 'response' => $response];
    }

    // -----------------------------------------------------------------------
    // 2. List carriers
    // -----------------------------------------------------------------------

    /**
     * POST /listCarriers — couriers available for the account (per EasyEcom Carrier API).
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function listCarriers(): array
    {
        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'Carrier not configured'];
        }

        try {
            $response = $this->client->listCarriers();
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[LIST_CARRIERS] exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $status = $response['_status'] ?? 0;
        if ($status >= 200 && $status < 300) {
            return ['success' => true, 'message' => 'OK', 'data' => $response];
        }

        $msg = $response['message'] ?? $response['error'] ?? 'HTTP ' . $status;
        return ['success' => false, 'message' => (string) $msg, 'data' => $response];
    }

    // -----------------------------------------------------------------------
    // 3. Create Shipment
    // -----------------------------------------------------------------------

    /**
     * Create shipment via EasyEcom Carrier API and persist AWB/courier/tracking to DB.
     *
     * @param array $orderData Must include order_id, reference_code, warehouse/location_key,
     *                        package dimensions, weight, order_items (SKU). Same structure as buildCarrierOrderData.
     * @return array{success: bool, message: string, data: array} data has awb_number, courier_name, tracking_url, etc.
     */
    public function createShipment(array $orderData): array
    {
        $ref = $orderData['reference_code'] ?? $orderData['order_no'] ?? '';
        log_message('info', self::LOG_PREFIX . '[CREATE_SHIPMENT] order=' . $ref);

        if (! $this->client->isConfigured()) {
            log_message('error', self::LOG_PREFIX . '[CREATE_SHIPMENT] carrier not configured');
            return ['success' => false, 'message' => 'Carrier not configured', 'data' => []];
        }

        $this->validateOrderDataForShipment($orderData);
        if (! empty($orderData['_validation_error'])) {
            log_message('error', self::LOG_PREFIX . '[CREATE_SHIPMENT] validation failed: ' . $orderData['_validation_error']);
            return ['success' => false, 'message' => $orderData['_validation_error'], 'data' => []];
        }

        try {
            $response = $this->client->createShipment($orderData);
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[CREATE_SHIPMENT] exception order=' . $ref . ' message=' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }

        $status = $response['_status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            $errorMsg = $response['message'] ?? $response['error'] ?? json_encode($response);
            log_message('error', self::LOG_PREFIX . '[CREATE_SHIPMENT] API failed order=' . $ref . ' status=' . $status . ' message=' . $errorMsg);
            return ['success' => false, 'message' => (string) $errorMsg, 'data' => $response];
        }

        $awbNumber   = $this->extractString($response, ['tracking_number', 'awb_number', 'awbNumber', 'awb']);
        $courierName = $this->extractString($response, ['carrier_name', 'courier', 'courierName', 'carrier']);
        $labelUrl    = $this->extractString($response, ['shipment_label', 'label_url', 'tracking_url', 'trackingUrl', 'tracking_link']);

        log_message('info', self::LOG_PREFIX . '[CREATE_SHIPMENT] awb=' . ($awbNumber ?: '-') . ' courier=' . ($courierName ?: 'Delhivery'));

        $updateData = [
            'shipment_status' => 'AWB_GENERATED',
        ];
        if ($awbNumber !== '') {
            $updateData['awb_number'] = $awbNumber;
        }
        if ($courierName !== '') {
            $updateData['courier_name'] = $courierName;
        }
        if ($labelUrl !== '') {
            $updateData['label_url'] = $labelUrl;
            $updateData['tracking_url'] = $labelUrl;
        }

        if ($ref !== '' && ! empty($updateData)) {
            $this->orderModel->update_order(['order_no' => $ref], $updateData);
        }

        return [
            'success' => true,
            'message' => 'Shipment created',
            'data'    => [
                'awb_number'   => $awbNumber,
                'courier_name' => $courierName,
                'tracking_url' => $labelUrl,
                'tracking_id'  => $awbNumber,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // 4. Cancel shipment
    // -----------------------------------------------------------------------

    /**
     * Cancel shipment by AWB. Trigger when order cancelled or shipment reassigned.
     *
     * @return array{success: bool, message: string}
     */
    public function cancelShipment(string $awb): array
    {
        $awb = trim($awb);
        if ($awb === '') {
            log_message('error', self::LOG_PREFIX . '[CANCEL_SHIPMENT] empty awb');
            return ['success' => false, 'message' => 'AWB required'];
        }

        log_message('info', self::LOG_PREFIX . '[CANCEL_SHIPMENT] awb=' . $awb);

        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'Carrier not configured'];
        }

        try {
            $response = $this->client->cancelShipment($awb);
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[CANCEL_SHIPMENT] exception awb=' . $awb . ' message=' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $status = $response['_status'] ?? 0;
        $success = $status >= 200 && $status < 300;
        if ($success) {
            log_message('info', self::LOG_PREFIX . '[CANCEL_SHIPMENT] awb=' . $awb . ' status=success');
        } else {
            log_message('error', self::LOG_PREFIX . '[CANCEL_SHIPMENT] awb=' . $awb . ' status=fail response=' . $status);
        }

        return [
            'success' => $success,
            'message' => $success ? 'Cancelled' : ($response['message'] ?? 'HTTP ' . $status),
        ];
    }

    // -----------------------------------------------------------------------
    // 5. Tracking Authorization
    // -----------------------------------------------------------------------

    /**
     * Get JWT for tracking updates. Token is stored in memory/cache for reuse.
     *
     * @return array{success: bool, token?: string, message: string}
     */
    public function authorizeTracking(): array
    {
        $cache = service('cache');
        if ($cache !== null) {
            $cached = $cache->get(self::TRACKING_TOKEN_CACHE_KEY);
            if ($cached !== null && $cached !== '') {
                log_message('info', self::LOG_PREFIX . '[TRACKING_AUTH] using cached token');
                return ['success' => true, 'token' => $cached, 'message' => 'From cache'];
            }
        }

        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'Carrier not configured'];
        }

        try {
            $response = $this->client->trackingAuth();
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[TRACKING_AUTH] exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $status = $response['_status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            $msg = $response['message'] ?? $response['error'] ?? 'HTTP ' . $status;
            return ['success' => false, 'message' => (string) $msg];
        }

        $token = $response['token'] ?? $response['access_token'] ?? $response['jwt'] ?? '';
        if ($token === '' && isset($response['data']['token'])) {
            $token = $response['data']['token'];
        }
        if ($token === '') {
            return ['success' => false, 'message' => 'No token in response'];
        }

        if ($cache !== null) {
            $cache->save(self::TRACKING_TOKEN_CACHE_KEY, $token, self::TRACKING_TOKEN_TTL);
        }

        log_message('info', self::LOG_PREFIX . '[TRACKING_AUTH] token obtained and cached');
        return ['success' => true, 'token' => $token, 'message' => 'OK'];
    }

    // -----------------------------------------------------------------------
    // 6. Update Tracking Status
    // -----------------------------------------------------------------------

    /**
     * Push tracking status to EasyEcom and update local order status.
     * Obtains JWT via tracking-auth first (required by Carrier API for updateTrackingStatus).
     *
     * @param array $trackingData current_shipment_status_id, awb (or awb_number), estimated_delivery_date, etc.
     * @return array{success: bool, message: string, updated: bool}
     */
    public function updateTrackingStatus(array $trackingData): array
    {
        $awb = $this->extractString($trackingData, ['awb', 'awb_number', 'awbNumber']);
        $statusId = $trackingData['current_shipment_status_id'] ?? $trackingData['status'] ?? $trackingData['currentShippingStatus'] ?? '';
        log_message('info', self::LOG_PREFIX . '[TRACKING_UPDATE] awb=' . ($awb ?: '-') . ' status=' . $statusId);

        if (! $this->client->isConfigured()) {
            return ['success' => false, 'message' => 'Carrier not configured', 'updated' => false];
        }

        $auth = $this->authorizeTracking();
        if (! $auth['success'] || empty($auth['token'])) {
            return [
                'success' => false,
                'message' => $auth['message'] ?? 'tracking-auth failed',
                'updated' => false,
            ];
        }

        try {
            $response = $this->client->updateTrackingStatus($trackingData, $auth['token']);
        } catch (\Throwable $e) {
            log_message('error', self::LOG_PREFIX . '[TRACKING_UPDATE] exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'updated' => false];
        }

        $httpOk = ($response['_status'] ?? 0) >= 200 && ($response['_status'] ?? 0) < 300;
        $updated = false;

        if ($awb !== '') {
            $order = $this->orderModel->where('awb_number', $awb)->first();
            if ($order !== null) {
                $updateData = $this->mapTrackingToOrderUpdate($trackingData);
                if (! empty($updateData)) {
                    $updated = $this->orderModel->update_order(['awb_number' => $awb], $updateData);
                }
            }
        }

        return [
            'success' => $httpOk,
            'message' => $httpOk ? 'OK' : ($response['message'] ?? 'API error'),
            'updated' => $updated,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateOrderDataForShipment(array &$orderData): void
    {
        $ref = $orderData['reference_code'] ?? $orderData['order_no'] ?? '';
        $items = $orderData['order_items'] ?? [];
        if ($ref === '') {
            $orderData['_validation_error'] = 'reference_code or order_no required';
            return;
        }
        if (! is_array($items) || empty($items)) {
            $orderData['_validation_error'] = 'order_items (SKU) required';
            return;
        }
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

    /**
     * Map tracking payload to order update fields (status, shipment_status, delivered_at, etc.)
     */
    private function mapTrackingToOrderUpdate(array $trackingData): array
    {
        $status = $trackingData['current_shipment_status_id'] ?? $trackingData['status']
            ?? $trackingData['currentShippingStatus'] ?? $trackingData['orderStatus'] ?? '';
        $s = strtoupper(trim((string) $status));

        $data = [];
        $data['shipment_status'] = $status;

        if (str_contains($s, 'DELIVERED') && ! str_contains($s, 'RTO')) {
            $data['status'] = 'DELIVERED';
            $data['delivered_at'] = $trackingData['estimated_delivery_date'] ?? date('Y-m-d H:i:s');
        } elseif (str_contains($s, 'RTO')) {
            $data['status'] = 'RTO_DELIVERED';
            $data['rto_at'] = date('Y-m-d H:i:s');
        } elseif (str_contains($s, 'OUT_FOR') || str_contains($s, 'OUT FOR')) {
            $data['status'] = 'IN_TRANSIT';
        } elseif (str_contains($s, 'TRANSIT') || $s === 'DISPATCHED' || str_contains($s, 'SHIPPED')) {
            $data['status'] = 'IN_TRANSIT';
        } elseif (str_contains($s, 'MANIFEST')) {
            $data['status'] = 'MANIFESTED';
        }

        if (isset($trackingData['estimated_delivery_date']) && $trackingData['estimated_delivery_date'] !== '') {
            $data['delivered_at'] = $trackingData['estimated_delivery_date'];
        }

        return $data;
    }
}
