<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Services\DeliveryTrackingService;
use App\Services\EasyEcomCarrierService;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivery Controller — Phase 2 (Delhivery via EasyEcom)
 *
 * Admin-facing endpoints for viewing and querying shipment/delivery data.
 * All routes require session authentication.
 *
 * Routes:
 *   GET  delivery/order           ?order_no=ORD001   — shipment details for one order
 *   GET  delivery/orders                             — all orders with shipment data
 *   GET  delivery/orders/shipped                     — only MANIFESTED/IN_TRANSIT orders
 *   GET  delivery/orders/pending-shipment            — CONFIRMED orders not yet manifested
 *   GET  delivery/carrier/list                      — EasyEcom Carrier listCarriers (debug/ops)
 *   GET  delivery/document        ?order_no=ORD001&doc_type=EPOD — fetch direct from Delhivery
 *   POST delivery/sync-status     {order_no, status} — manually override shipment status (admin)
 */
class Delivery extends BaseController
{
    use ResponseTrait;

    private OrderModel $orderModel;
    private DeliveryTrackingService $deliveryService;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->orderModel      = new OrderModel();
        $this->deliveryService = new DeliveryTrackingService($this->orderModel);
        helper(['api_helper']);
    }

    // -----------------------------------------------------------------------
    // GET delivery/order?order_no=ORD001
    // -----------------------------------------------------------------------

    /**
     * Return full shipment details for a single order.
     */
    public function order(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $orderNo = trim((string) ($this->request->getGet('order_no') ?? ''));
        if ($orderNo === '') {
            return json_error('order_no is required', 400);
        }

        $data = $this->deliveryService->getShipmentData($orderNo);
        if ($data === null) {
            return json_error('Order not found', 404);
        }

        return json_success($data);
    }

    // -----------------------------------------------------------------------
    // GET delivery/orders
    // -----------------------------------------------------------------------

    /**
     * List all orders with their shipment data (descending by order ID).
     * Supports optional ?status= filter and ?limit= (default 100).
     */
    public function orders(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $statusFilter = $this->request->getGet('status');
        $limit        = min((int) ($this->request->getGet('limit') ?? 100), 500);

        $db      = \Config\Database::connect();
        $builder = $db->table('orders as o');
        $builder->select([
            'o.id', 'o.order_no', 'o.status', 'o.pay_status', 'o.total_amount',
            'o.awb_number', 'o.courier_name', 'o.shipment_status', 'o.fulfillment_status',
            'o.label_url', 'o.easyecom_order_id',
            'o.shipped_at', 'o.delivered_at', 'o.rto_at', 'o.cancelled_at',
            'o.created_at', 'u.fullname as customer_name',
        ]);
        $builder->join('users as u', 'u.id = o.user_id', 'left');

        if (! empty($statusFilter)) {
            $builder->where('o.status', strtoupper($statusFilter));
        }

        $builder->orderBy('o.id', 'DESC');
        $builder->limit($limit);

        $rows = $builder->get()->getResultArray();
        return json_success($rows);
    }

    // -----------------------------------------------------------------------
    // GET delivery/orders/shipped
    // -----------------------------------------------------------------------

    /**
     * Orders that have been manifested or are in-transit (active shipments).
     */
    public function shipped(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $db      = \Config\Database::connect();
        $builder = $db->table('orders as o');
        $builder->select([
            'o.id', 'o.order_no', 'o.status', 'o.awb_number', 'o.courier_name',
            'o.shipment_status', 'o.label_url', 'o.shipped_at', 'o.easyecom_order_id',
            'o.created_at', 'u.fullname as customer_name',
        ]);
        $builder->join('users as u', 'u.id = o.user_id', 'left');
        $builder->whereIn('o.status', ['MANIFESTED', 'IN_TRANSIT']);
        $builder->orderBy('o.shipped_at', 'DESC');

        $rows = $builder->get()->getResultArray();
        return json_success($rows);
    }

    // -----------------------------------------------------------------------
    // GET delivery/orders/pending-shipment
    // -----------------------------------------------------------------------

    /**
     * CONFIRMED orders that have no AWB yet — awaiting fulfilment by warehouse.
     */
    public function pendingShipment(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $db      = \Config\Database::connect();
        $builder = $db->table('orders as o');
        $builder->select([
            'o.id', 'o.order_no', 'o.status', 'o.total_amount', 'o.easyecom_order_id',
            'o.created_at', 'u.fullname as customer_name',
        ]);
        $builder->join('users as u', 'u.id = o.user_id', 'left');
        $builder->where('o.status', 'CONFIRMED');
        $builder->where('(o.awb_number IS NULL OR o.awb_number = \'\')', null, false);
        $builder->orderBy('o.created_at', 'ASC');

        $rows = $builder->get()->getResultArray();
        return json_success($rows);
    }

    // -----------------------------------------------------------------------
    // GET delivery/document?order_no=ORD001&doc_type=EPOD
    // -----------------------------------------------------------------------

    /**
     * Fetch direct documents from the courier (e.g. EPOD from Delhivery API)
     * Utilized in the Hybrid architecture since EasyEcom webhooks do not send EPODs.
     */
    public function document(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $orderNo = trim((string) ($this->request->getGet('order_no') ?? ''));
        $docType = strtoupper(trim((string) ($this->request->getGet('doc_type') ?? 'EPOD')));

        if ($orderNo === '') {
            return json_error('order_no is required', 400);
        }

        // Get the order delivery details
        $data = $this->deliveryService->getShipmentData($orderNo);
        if ($data === null) {
            return json_error('Order not found', 404);
        }

        $awb = $data['awb_number'] ?? '';
        $courier = strtolower($data['courier_name'] ?? '');

        if ($awb === '') {
            return json_error('Order has no AWB tracking number yet (Not shipped)', 400);
        }

        return json_error('Direct document fetching is currently disabled. All delivery tracking is managed via EasyEcom', 400);
    }

    // -----------------------------------------------------------------------
    // GET delivery/carrier/list
    // -----------------------------------------------------------------------

    /**
     * Call EasyEcom Carrier POST /listCarriers — available couriers for the configured account.
     * See https://api-docs.easyecom.io/#72423b60-baae-42ab-a0bb-7ee9699ff035
     */
    public function carrierList(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        /** @var EasyEcomCarrierService $carrier */
        $carrier = service('easyecomCarrier');
        if (! $carrier->isConfigured()) {
            return json_error('Carrier API not configured. Set EASYECOM_CARRIER_BASE_URL, USERNAME, PASSWORD in .env.', 503);
        }

        $result = $carrier->listCarriers();
        if (! $result['success']) {
            return json_error($result['message'] ?? 'listCarriers failed', 502);
        }

        return json_success($result['data'] ?? [], 'OK');
    }

    // -----------------------------------------------------------------------
    // POST delivery/sync-status
    // -----------------------------------------------------------------------

    /**
     * Manually update shipment status for an order (admin override).
     *
     * Body: { "order_no": "ORD001", "status": "DELIVERED", "awb_number": "..." }
     */
    public function syncStatus(): \CodeIgniter\HTTP\Response
    {
        if (! check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        if (! $this->request->is('post')) {
            return $this->respond(['success' => false, 'message' => 'Method not allowed'], 405);
        }

        $json    = $this->request->getJSON(true) ?: [];
        $orderNo = trim((string) ($json['order_no'] ?? ''));
        $status  = strtoupper(trim((string) ($json['status'] ?? '')));

        if ($orderNo === '') {
            return json_error('order_no is required', 400);
        }

        $allowed = ['MANIFESTED', 'IN_TRANSIT', 'DELIVERED', 'RTO_DELIVERED', 'CANCELLED'];
        if (! in_array($status, $allowed, true)) {
            return json_error('Invalid status. Allowed: ' . implode(', ', $allowed), 400);
        }

        $data = ['status' => $status, 'shipment_status' => $status];

        $awb = trim((string) ($json['awb_number'] ?? ''));
        if ($awb !== '') {
            $data['awb_number'] = $awb;
        }

        $courier = trim((string) ($json['courier_name'] ?? ''));
        if ($courier !== '') {
            $data['courier_name'] = $courier;
        }

        $now = date('Y-m-d H:i:s');
        match ($status) {
            'MANIFESTED', 'IN_TRANSIT' => $data['shipped_at']   = $now,
            'DELIVERED'                => $data['delivered_at']  = $now,
            'RTO_DELIVERED'            => $data['rto_at']        = $now,
            'CANCELLED'                => $data['cancelled_at']  = $now,
            default                    => null,
        };

        $ok = $this->orderModel->update_order(['order_no' => $orderNo], $data);
        if (! $ok) {
            return json_error('Order not found or status unchanged', 404);
        }

        log_message('info', 'Delivery: [SYNC_STATUS] admin override order=' . $orderNo . ' status=' . $status);
        return json_success(null, 'Status updated successfully');
    }
}
