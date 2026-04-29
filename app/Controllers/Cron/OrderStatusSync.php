<?php

namespace App\Controllers\Cron;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Services\DeliveryTrackingService;

/**
 * Cron: Poll EasyEcom for order status changes (fallback when webhooks miss).
 * GET /cron/order-status-sync?secret=<CRON_SECRET>
 *
 * Uses getAllOrdersV2 with updated_after = last 30 minutes.
 */
class OrderStatusSync extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $secret = env('CRON_SECRET', '');
        if ($secret !== '' && $this->request->getGet('secret') !== $secret) {
            return $this->respond(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Disabled to avoid EasyEcom auth rate limits (429). Remove this block to re-enable.
        return $this->respond([
            'success'  => true,
            'message'  => 'EasyEcom order-status-sync cron disabled to avoid auth rate limits.',
            'polled'   => 0,
            'updated'  => 0,
            'failed'   => [],
            'disabled' => true,
        ], 200);

        try {
            $client = service('easyecom');
            if (! $client->isConfigured()) {
                return $this->respond(['success' => false, 'message' => 'EasyEcom not configured'], 503);
            }

            $updatedAfter = date('Y-m-d H:i:s', strtotime('-30 minutes'));
            $response = $client->getAllOrders(['updated_after' => $updatedAfter]);

            $orders = $response['data']['orders'] ?? $response['data'] ?? $response['orders'] ?? [];
            if (! is_array($orders)) {
                $orders = [];
            }

            $deliveryService = new DeliveryTrackingService();
            $result          = $deliveryService->processTracking(['orders' => $orders]);

            log_message(
                'info',
                'Cron OrderStatusSync: polled=' . count($orders)
                . ' updated=' . ($result['updated'] ?? 0)
                . ' failed=' . count($result['failed'] ?? [])
                . ' disabled=' . (($result['disabled'] ?? false) ? 'true' : 'false')
            );
            return $this->respond([
                'success' => true,
                'polled'  => count($orders),
                'updated' => $result['updated'] ?? 0,
                'failed'  => $result['failed'] ?? [],
                'disabled' => $result['disabled'] ?? false,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Cron OrderStatusSync: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
