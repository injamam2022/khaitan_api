<?php

namespace App\Controllers;

use App\Models\OrderModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Order extends BaseController
{
    use ResponseTrait;

    protected $orderModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->orderModel = new OrderModel();
        helper(['api_helper']);
    }

    /**
     * Push order to EasyEcom directly.
     * POST JSON: { "order_no": "..." }. Header: X-EasyEcom-Push-Secret.
     */
    public function pushToEasyEcom()
    {
        log_message('info', 'EasyEcom: [PUSH_ORDER] endpoint hit method=' . $this->request->getMethod());

        if (!$this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }

        $secret = env('EASYECOM_PUSH_SECRET', '');
        $headerSecret = $this->request->getHeaderLine('X-EasyEcom-Push-Secret');
        if ($secret === '' || $headerSecret !== $secret) {
            return $this->respond(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $json = $this->request->getJSON(true) ?: [];
        $order_no = $json['order_no'] ?? $this->request->getPost('order_no') ?? '';
        if ($order_no === '') {
            return $this->respond(['success' => false, 'message' => 'order_no is required'], 400);
        }

        // Non-blocking: call EasyEcom Create Order API; never break checkout flow
        $result = \App\Libraries\EasyEcomSyncService::fire(function ($service) use ($order_no) {
            return $service->createEasyEcomOrder(['order_no' => $order_no]);
        });

        if ($result['success']) {
            return $this->respond([
                'success'  => true,
                'message'  => $result['message'] ?? 'Order pushed to EasyEcom',
                'order_no' => $order_no,
                'data'     => $result['data'] ?? [],
            ]);
        }

        // EasyEcom failed but local order is already saved — return 200 so checkout is not broken
        return $this->respond([
            'success'  => false,
            'message'  => $result['message'] ?? 'EasyEcom order creation failed; order saved locally.',
            'order_no' => $order_no,
            'data'     => $result['data'] ?? [],
        ], 200);
    }

    /**
     * Cancel order: update local DB to cancelled, then notify EasyEcom.
     * POST JSON: { "order_no": "..." }. Header: X-EasyEcom-Push-Secret.
     * Flow: Update Order Status in DB → Call EasyEcom Cancel Order API → Log Result.
     */
    public function cancelOnEasyEcom()
    {
        log_message('info', 'EasyEcom: [CancelOrder] cancelOnEasyEcom endpoint hit method=' . $this->request->getMethod());

        if (! $this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }

        $secret = env('EASYECOM_PUSH_SECRET', '');
        $headerSecret = $this->request->getHeaderLine('X-EasyEcom-Push-Secret');
        if ($secret === '' || $headerSecret !== $secret) {
            return $this->respond(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $json   = $this->request->getJSON(true) ?: [];
        $order_no = $json['order_no'] ?? $this->request->getPost('order_no') ?? '';
        if ($order_no === '') {
            return $this->respond(['success' => false, 'message' => 'order_no is required'], 400);
        }

        $orderDetails = $this->orderModel->getOrderDetails($order_no);
        if (empty($orderDetails) || empty($orderDetails['od'])) {
            return $this->respond(['success' => false, 'message' => 'Order not found in local records'], 404);
        }

        $shipmentStatus = strtoupper((string) ($orderDetails['od']['shipment_status'] ?? ''));
        if (in_array($shipmentStatus, ['IN_TRANSIT', 'MANIFESTED', 'DELIVERED'])) {
            return $this->respond(['success' => false, 'message' => 'Order cannot be cancelled. Active shipment (' . $shipmentStatus . ') exists.'], 400);
        }

        // 1. Update local DB order status to cancelled
        $this->orderModel->update_order(['order_no' => $order_no], ['status' => 'cancelled']);
        log_message('info', 'EasyEcom: [CancelOrder] local order status updated to cancelled order_no=' . $order_no);

        // 2. Call EasyEcom Cancel Order API (non-blocking; local cancel is already saved)
        $result = \App\Libraries\EasyEcomSyncService::fire(function ($service) use ($order_no) {
            return $service->cancelEasyEcomOrder($order_no);
        });

        if ($result['success']) {
            return $this->respond([
                'success'  => true,
                'message'  => $result['message'] ?? 'Order cancelled locally and on EasyEcom',
                'order_no' => $order_no,
                'data'     => $result['data'] ?? [],
            ]);
        }

        // EasyEcom API failed but local cancellation is recorded
        return $this->respond([
            'success'  => true,
            'message'  => 'Order cancelled locally. EasyEcom cancel failed: ' . ($result['message'] ?? 'unknown'),
            'order_no' => $order_no,
            'data'     => ['easyecom_sync' => false, 'detail' => $result['message'] ?? ''],
        ], 200);
    }

    public function index()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $this->orderModel->markPendingOrdersAsFailed(5);
        $orders = $this->orderModel->getOrderList();
        return json_success($orders);
    }

    public function lists()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $this->orderModel->markPendingOrdersAsFailed(5);
        $orders = $this->orderModel->getOrderList();
        return json_success($orders);
    }

    public function orderDetails()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $order_no = $this->request->getGet('order_no');

        if (empty($order_no)) {
            return json_error('Order number is required', 400);
        }

        $res = $this->orderModel->getOrderDetails($order_no);

        if ($res && isset($res['od'])) {
            return json_success([
                'order' => $res['od'],
                'items' => $res['item'],
            ]);
        } else {
            return json_error('Order not found', 404);
        }
    }

    public function editOrder()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $order_no = $this->request->getGet('order_no') ?: $this->request->getPost('order_no');
        if (empty($order_no)) {
            $json = $this->request->getJSON(true);
            if (!empty($json['order_no'])) {
                $order_no = $json['order_no'];
            }
        }
        if (empty($order_no)) {
            return json_error('Order number is required', 400);
        }

        if ($this->request->is('post')) {
            $json = $this->request->getJSON(true) ?: [];
            $order_status = $json['order_status'] ?? $this->request->getPost('order_status');

            if (empty($order_status)) {
                return json_error('Order status is required', 400);
            }

            $where = ['order_no' => $order_no];
            $dataArr = ['status' => $order_status];

            $chk = $this->orderModel->update_order($where, $dataArr);

            if ($chk == 1) {
                // When customer cancels (status = cancelled), notify EasyEcom
                if (strtolower((string) $order_status) === 'cancelled') {
                    \App\Libraries\EasyEcomSyncService::fire(function ($service) use ($order_no) {
                        return $service->cancelEasyEcomOrder($order_no);
                    });
                }
                return json_success(null, 'Order status updated successfully');
            } else {
                return json_error('Failed to update order status', 500);
            }
        } else {
            $res = $this->orderModel->getOrderDetails($order_no);

            if ($res && isset($res['od'])) {
                return json_success([
                    'order' => $res['od'],
                    'items' => $res['item'],
                ]);
            } else {
                return json_error('Order not found', 404);
            }
        }
    }
}
