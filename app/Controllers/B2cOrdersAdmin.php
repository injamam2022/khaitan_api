<?php

namespace App\Controllers;

use App\Models\B2cOrderModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin: B2C paid orders (separate from bulk enquiries and main orders).
 */
class B2cOrdersAdmin extends BaseController
{
    protected B2cOrderModel $b2cOrderModel;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->b2cOrderModel = new B2cOrderModel();
        helper(['api_helper']);
    }

    /**
     * GET /b2c-orders?page=1&limit=50&pay_status=
     */
    public function index(): ResponseInterface
    {
        check_auth();

        $limit = min(200, max(1, (int) ($this->request->getGet('limit') ?: 50)));
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));
        $payStatus = trim((string) ($this->request->getGet('pay_status') ?? ''));

        $builder = $this->b2cOrderModel->builder();
        if ($payStatus !== '' && in_array($payStatus, ['pending', 'paid', 'failed', 'cancelled'], true)) {
            $builder->where('pay_status', $payStatus);
        }

        $total = (int) $builder->countAllResults(false);
        $totalPages = (int) max(1, (int) ceil($total / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $builder
            ->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();

        return json_success([
            'orders' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /**
     * GET /b2c-orders/(:num)
     */
    public function show($id = null): ResponseInterface
    {
        check_auth();

        $orderId = (int) $id;
        if ($orderId <= 0) {
            return json_error('Invalid order ID', 400);
        }

        $order = $this->b2cOrderModel->getOrderWithItems($orderId);
        if (!$order) {
            return json_error('Order not found', 404);
        }

        return json_success($order);
    }
}
