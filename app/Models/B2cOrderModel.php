<?php

namespace App\Models;

use CodeIgniter\Model;

class B2cOrderModel extends Model
{
    protected $table = 'b2c_orders';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'order_no',
        'customer_name',
        'customer_email',
        'customer_phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'pincode',
        'gross_amount',
        'gst_amount',
        'discount_amount',
        'paid_amount',
        'pay_status',
        'pay_gateway_name',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'txn_id',
        'notes',
        'created_at',
        'updated_at',
    ];

    public function generateOrderNo(): string
    {
        return 'B2C' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public function getOrderWithItems(int $orderId): ?array
    {
        $order = $this->find($orderId);
        if (!$order) {
            return null;
        }

        $items = $this->db->table('b2c_order_items')
            ->where('b2c_order_id', $orderId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $order['items'] = $items;

        return $order;
    }

    public function getByOrderNo(string $orderNo): ?array
    {
        $order = $this->where('order_no', $orderNo)->first();
        if (!$order) {
            return null;
        }

        return $this->getOrderWithItems((int) $order['id']);
    }

    public function insertItems(int $orderId, array $items): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            $this->db->table('b2c_order_items')->insert([
                'b2c_order_id'  => $orderId,
                'product_id'    => (int) ($item['product_id'] ?? 0),
                'variation_id'  => !empty($item['variation_id']) ? (int) $item['variation_id'] : null,
                'product_name'  => (string) ($item['product_name'] ?? ''),
                'sku'           => $item['sku'] ?? null,
                'qty'           => max(1, (int) ($item['qty'] ?? 1)),
                'unit_price'    => (float) ($item['unit_price'] ?? 0),
                'line_total'    => (float) ($item['line_total'] ?? 0),
                'created_at'    => $now,
            ]);
        }
    }
}
