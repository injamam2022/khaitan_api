<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\EasyEcomSkuMappingModel;
use App\Services\DeliveryTrackingService;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EasyEcom;
use Psr\Log\LoggerInterface;

/**
 * Unified EasyEcom webhook controller.
 * Single endpoint: POST webhooks/easyecom
 *
 * Routes events via x-easyecom-webhook-action header to shared services.
 * Auth: IP allowlist (EASYECOM_WEBHOOK_ALLOWED_IPS) and/or X-Easyecom-Company-Id header.
 * EasyEcom does not send Bearer/Access-Token on webhooks.
 *
 * Always returns 200 (except 401) to prevent EasyEcom retries.
 */
class Webhooks extends BaseController
{
    use ResponseTrait;

    protected OrderModel $orderModel;
    protected EasyEcom $easyecomConfig;
    protected DeliveryTrackingService $deliveryService;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->orderModel      = new OrderModel();
        $this->easyecomConfig  = config('EasyEcom');
        $this->deliveryService = new DeliveryTrackingService($this->orderModel);
    }

    /**
     * EasyEcom webhook: POST webhooks/easyecom
     *
     * 1. Validates Access-Token / Authorization Bearer against EASYECOM_WEBHOOK_SECRET.
     * 2. Routes by x-easyecom-webhook-action header.
     * 3. Delegates to DeliveryTrackingService for manifested/tracking events.
     * 4. Always returns 200 { success, received } (except 401) to prevent retries.
     */
    public function easyEcomHandler()
    {
        $ip     = $this->request->getIPAddress();
        $method = $this->request->getMethod();
        log_message('info', 'EasyEcom webhook: request IP=' . $ip . ' method=' . $method);
        $this->logIncomingWebhookRequest();

        if (! $this->request->is('post')) {
            log_message('error', 'EasyEcom webhook: method not allowed');
            return $this->respond(['error' => 'Method not allowed'], 405);
        }

        if (! $this->validateWebhookByIpOrCompanyId($ip)) {
            log_message('error', 'EasyEcom webhook: auth failed from IP=' . $ip . ' — IP not in allowlist and X-Easyecom-Company-Id not valid. Allowed IPs: ' . implode(', ', $this->easyecomConfig->webhookAllowedIps ?? []));
            return $this->respond(['error' => 'Unauthorized'], 401);
        }

        $action = $this->request->getHeaderLine('x-easyecom-webhook-action');
        log_message('info', 'EasyEcom webhook: action=' . $action);

        $payload = $this->request->getJSON(true);
        if (! is_array($payload)) {
            $payload = is_object($payload) ? json_decode(json_encode($payload), true) : [];
        }

        switch ($action) {
            case 'CreateOrderV2':
            case 'FetchOrderV2':
            case 'ConfirmOrderV2':
            case 'UpdateOrderV2':
                $this->logWebhookPayload($payload);
                $this->updateOrdersFromPayload($payload, 'CONFIRMED');
                if ($action === 'UpdateOrderV2' || $action === 'ConfirmOrderV2') {
                    $this->triggerShipmentCreationForConfirmedOrders($payload);
                }
                break;

            case 'ManifestedV2':
                $this->logWebhookPayload($payload);
                $normalized = $this->normalizeToOrdersWrapper($payload);
                $result = $this->deliveryService->processManifested($normalized);
                if ($result['disabled'] ?? false) {
                    $this->updateOrdersFromPayload($payload, 'MANIFESTED');
                }
                break;

            case 'CancelOrderV2':
                $this->logWebhookPayload($payload);
                $this->updateOrdersFromPayload($payload, 'CANCELLED');
                break;

            case 'UpdateInventoryV2':
            case 'UpdateInventory':
                $this->processInventoryUpdate($payload);
                break;

            case 'MarkReturnV2':
                $this->processReturn($payload);
                break;

            default:
                log_message('info', 'EasyEcom webhook: unhandled action=' . $action);
                break;
        }

        return $this->respond(['success' => true, 'received' => true], 200);
    }

    /**
     * Update order status + easyecom_order_id from webhook payload.
     * Handles both { orders: [...] } and single-order structures.
     */
    private function updateOrdersFromPayload(array $payload, string $targetStatus): void
    {
        $orders = $this->extractOrderEntries($payload);
        if (empty($orders)) {
            log_message('info', 'EasyEcom webhook: no order references in payload for status=' . $targetStatus);
            return;
        }

        foreach ($orders as $entry) {
            $ref = $entry['reference_code'];
            $data = ['status' => $targetStatus];

            $eeOrderId = $this->extractString($entry['order'], ['order_id', 'easyecom_order_id']);
            if ($eeOrderId !== '') {
                $data['easyecom_order_id'] = $eeOrderId;
            }

            $awb = $this->extractString($entry['order'], ['awb_number', 'awbNumber', 'tracking_number']);
            if ($awb !== '') {
                $data['awb_number'] = $awb;
            }

            $this->orderModel->update_order(['order_no' => $ref], $data);
            log_message('info', 'EasyEcom webhook: order=' . $ref . ' → ' . $targetStatus);
        }
    }

    /**
     * When UpdateOrderV2/ConfirmOrderV2 reports order_status = Confirmed, trigger shipment creation.
     * Non-blocking: uses EasyEcomSyncService::fire so failures do not break the webhook pipeline.
     */
    private function triggerShipmentCreationForConfirmedOrders(array $payload): void
    {
        $orders = $this->extractOrderEntries($payload);
        foreach ($orders as $entry) {
            $orderStatus = $this->extractString($entry['order'], ['order_status', 'orderStatus', 'status']);
            if (strtoupper($orderStatus) !== 'CONFIRMED') {
                continue;
            }
            $ref = $entry['reference_code'];
            if ($ref === '') {
                continue;
            }
            try {
                \App\Libraries\EasyEcomSyncService::fire(function ($service) use ($ref) {
                    return $service->createEasyEcomShipment($ref);
                });
            } catch (\Throwable $e) {
                log_message('error', 'EasyEcom webhook: [CREATE_SHIPMENT] exception reference_code=' . $ref . ' message=' . $e->getMessage());
            }
        }
    }

    /**
     * Process inventory update from unified webhook.
     * Gated by INVENTORY_SYNC_ENABLED feature flag.
     */
    private function processInventoryUpdate(array $payload): void
    {
        $enabled = filter_var(env('INVENTORY_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            log_message('info', 'EasyEcom webhook: UpdateInventory received but INVENTORY_SYNC_ENABLED=false');
            return;
        }

        $items = $payload['inventoryData'] ?? $payload['items'] ?? $payload['skus'] ?? [];
        if (! is_array($items)) {
            return;
        }

        $skuMapping   = new EasyEcomSkuMappingModel();
        $productModel = new ProductModel();
        $updated = 0;

        foreach ($items as $item) {
            $eeSku = (string) ($item['sku'] ?? $item['seller_sku'] ?? '');
            $qty   = isset($item['quantity']) ? (int) $item['quantity'] : null;
            if ($eeSku === '' || $qty === null) {
                continue;
            }
            $internalSku = $skuMapping->resolveFromEasyEcom($eeSku);
            $result = $productModel->updateStockBySku($internalSku, $qty);
            if ($result['updated']) {
                $updated++;
            }
        }
        log_message('info', 'EasyEcom webhook: UpdateInventory processed items=' . count($items) . ' updated=' . $updated);
    }

    /**
     * Process return/credit-note from unified webhook.
     */
    private function processReturn(array $payload): void
    {
        $creditNotes = $payload['credit_notes'] ?? [];
        if (! is_array($creditNotes) || empty($creditNotes)) {
            log_message('info', 'EasyEcom webhook: MarkReturnV2 received but no credit_notes');
            return;
        }

        foreach ($creditNotes as $note) {
            $ref = (string) ($note['reference_code'] ?? $note['order_no'] ?? '');
            if ($ref === '') {
                continue;
            }
            $this->orderModel->update_order(['order_no' => $ref], ['status' => 'RETURNED']);
            log_message('info', 'EasyEcom webhook: MarkReturnV2 order=' . $ref . ' → RETURNED');
        }
    }

    /**
     * Normalize payload to { orders: [...] } format for DeliveryTrackingService.
     */
    private function normalizeToOrdersWrapper(array $payload): array
    {
        if (isset($payload['orders']) && is_array($payload['orders'])) {
            return $payload;
        }
        if (isset($payload[0]) && is_array($payload[0])) {
            return ['orders' => $payload];
        }
        $ref = $payload['reference_code'] ?? $payload['order_no'] ?? null;
        if ($ref !== null && $ref !== '') {
            return ['orders' => [$payload]];
        }
        return $payload;
    }

    /**
     * Extract order entries from webhook payload.
     *
     * @return list<array{reference_code: string, order: array}>
     */
    private function extractOrderEntries(array $payload): array
    {
        $orderData = isset($payload[0]) && is_array($payload[0]) ? $payload[0] : $payload;
        if (isset($orderData['orders']) && is_array($orderData['orders'])) {
            $list = $orderData['orders'];
        } else {
            $list = [$orderData];
        }

        $out = [];
        foreach ($list as $order) {
            if (! is_array($order)) {
                continue;
            }
            $ref = $this->extractString($order, ['reference_code', 'order_no', 'order_reference', 'channel_order_id', 'reference_id']);
            if ($ref !== '') {
                $out[] = ['reference_code' => $ref, 'order' => $order];
            }
        }
        return $out;
    }

    /**
     * Validate EasyEcom webhook by IP allowlist and/or X-Easyecom-Company-Id header.
     * EasyEcom webhooks do not send Bearer or Access-Token; use IP/header instead.
     */
    private function validateWebhookByIpOrCompanyId(string $ip): bool
    {
        $allowedIps = $this->easyecomConfig->webhookAllowedIps ?? [];
        if (is_array($allowedIps) && in_array($ip, $allowedIps, true)) {
            return true;
        }

        $companyId = $this->easyecomConfig->webhookCompanyId ?? '';
        if ($companyId !== '') {
            $headerCompanyId = trim($this->request->getHeaderLine('X-Easyecom-Company-Id'));
            if ($headerCompanyId !== '' && hash_equals($companyId, $headerCompanyId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log incoming webhook request with masked sensitive headers.
     */
    private function logIncomingWebhookRequest(): void
    {
        $logDir = WRITEPATH . 'logs';
        if (! is_dir($logDir)) {
            return;
        }
        $date = date('Y-m-d');
        $file = $logDir . DIRECTORY_SEPARATOR . 'easyecom_webhook_' . $date . '.log';

        $headers = [];
        foreach ($this->request->getHeaders() as $name => $header) {
            $key = $name;
            $val = $header->getValue();
            if (strtolower($key) === 'access-token' || strtolower($key) === 'authorization') {
                $val = $val !== '' ? '***' : '(empty)';
            }
            $headers[$key] = $val;
        }
        $rawBody = (string) $this->request->getBody();
        $bodyPreview = strlen($rawBody) > 2048 ? substr($rawBody, 0, 2048) . '...[truncated]' : $rawBody;

        $line = date('Y-m-d H:i:s') . ' [REQUEST] IP=' . $this->request->getIPAddress()
            . ' method=' . $this->request->getMethod()
            . PHP_EOL . 'Headers: ' . json_encode($headers)
            . PHP_EOL . 'Body: ' . $bodyPreview
            . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log webhook payload to dedicated file for debugging.
     */
    private function logWebhookPayload(array $payload): void
    {
        $date   = date('Y-m-d');
        $logDir = WRITEPATH . 'logs';
        if (! is_dir($logDir)) {
            return;
        }
        $file = $logDir . DIRECTORY_SEPARATOR . 'easyecom_webhook_' . $date . '.log';
        $line = date('Y-m-d H:i:s') . ' ' . $this->request->getIPAddress() . ' ' . json_encode($payload) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Extract first non-empty string from payload using a list of possible keys.
     */
    private function extractString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $val = $payload[$key] ?? null;
            if ($val !== null && $val !== '') {
                return is_string($val) ? trim($val) : (string) $val;
            }
        }
        return '';
    }
}
