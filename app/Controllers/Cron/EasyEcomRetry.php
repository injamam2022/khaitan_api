<?php

namespace App\Controllers\Cron;

use App\Controllers\BaseController;
use App\Libraries\EasyEcomSyncService;
use App\Models\OrderModel;
use CodeIgniter\API\ResponseTrait;

/**
 * Cron Job: Retry failed EasyEcom synchronizations.
 * Run this every 15-30 minutes.
 * 
 * Example: GET /cron/easyecom-retry?secret=YOUR_CRON_SECRET
 */
class EasyEcomRetry extends BaseController
{
    use ResponseTrait;

    protected $orderModel;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
    }

    public function index()
    {
        // 1. Security Check (Basic secret-based auth for cron)
        $secret = $this->request->getGet('secret');
        $expectedSecret = env('CRON_SECRET', 'default_sync_secret');
        
        if ($secret !== $expectedSecret) {
            log_message('error', 'Cron EasyEcomRetry: Unauthorized access attempt');
            return $this->failUnauthorized('Invalid secret');
        }

        log_message('info', 'Cron EasyEcomRetry: Started retry process');

        // 2. Find failed orders (where sync status is FAILED and pay_status is PAID/SUCCESS or mode is COD)
        // Note: For Online orders, we only retry if pay_status is confirmed.
        $db = \Config\Database::connect();
        $builder = $db->table('orders');
        $builder->select('order_no, id, pay_mode, pay_status');
        $builder->where('easyecom_sync_status', 'FAILED');
        $builder->groupStart()
                ->where('pay_mode', 'COD')
                ->orGroupStart()
                    ->where('pay_mode', 'ONLINE')
                    ->whereIn('pay_status', ['PAID', 'SUCCESS', 'PAYMENT_PAID'])
                ->groupEnd()
        ->groupEnd();
        
        $failedOrders = $builder->get()->getResultArray();
        
        if (empty($failedOrders)) {
            log_message('info', 'Cron EasyEcomRetry: No failed orders found to retry');
            return $this->respond(['success' => true, 'message' => 'No failed orders to retry']);
        }

        $results = [
            'total'     => count($failedOrders),
            'succeeded' => 0,
            'failed'    => 0,
            'details'   => []
        ];

        // 3. Loop and retry
        foreach ($failedOrders as $order) {
            $orderNo = $order['order_no'];
            
            log_message('info', "Cron EasyEcomRetry: Retrying sync for order {$orderNo}");

            $syncResult = EasyEcomSyncService::fire(function ($service) use ($orderNo) {
                return $service->createEasyEcomOrder(['order_no' => $orderNo]);
            });

            if ($syncResult['success']) {
                $results['succeeded']++;
            } else {
                $results['failed']++;
            }

            $results['details'][] = [
                'order_no' => $orderNo,
                'status'   => $syncResult['success'] ? 'SUCCESS' : 'FAILED',
                'message'  => $syncResult['message'] ?? ''
            ];
        }

        log_message('info', "Cron EasyEcomRetry: Completed. Succeeded: {$results['succeeded']}, Failed: {$results['failed']}");

        return $this->respond(['success' => true, 'results' => $results]);
    }
}
