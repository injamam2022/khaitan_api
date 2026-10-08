<?php

namespace App\Controllers;

use App\Libraries\RazorpayService;
use App\Models\B2cOrderModel;
use App\Models\ProductModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Storefront B2C sales (separate from bulk enquiries and main cart orders).
 */
class B2cSales extends BaseController
{
    protected ProductModel $productModel;
    protected B2cOrderModel $b2cOrderModel;

    public function __construct()
    {
        helper(['api_helper']);
        $this->productModel = new ProductModel();
        $this->b2cOrderModel = new B2cOrderModel();
    }

    /**
     * POST /api/b2c/products/list
     */
    public function productsList(): ResponseInterface
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $page = max(1, (int) ($payload['page'] ?? 1));
            $limit = min(100, max(1, (int) ($payload['limit'] ?? 24)));
            $offset = ($page - 1) * $limit;
            $searchTerms = trim((string) ($payload['searchTerms'] ?? ''));

            $priceExpr = "COALESCE(NULLIF(P.final_price, 0), NULLIF(P.sale_price, 0), NULLIF(P.product_price, 0), NULLIF(P.mrp, 0), 0)";

            $builder = $this->productModel->db->table('products AS P');
            $builder->select("
                P.id,
                P.product_name,
                P.slug,
                P.short_description,
                P.is_b2c_sale,
                {$priceExpr} AS min_price,
                (SELECT PI.image
                 FROM product_image AS PI
                 WHERE PI.product_id = CAST(P.id AS UNSIGNED)
                   AND PI.status <> 'DELETED'
                 ORDER BY PI.display_order ASC, PI.id ASC
                 LIMIT 1) AS primary_image
            ");
            $builder->where('P.status', 'ACTIVE');
            $builder->where('P.is_b2c_sale', 1);
            $builder->where("{$priceExpr} >", 0, false);

            if ($searchTerms !== '') {
                $builder->groupStart()
                    ->like('P.product_name', $searchTerms)
                    ->orLike('P.slug', $searchTerms)
                    ->groupEnd();
            }

            $builder->orderBy('P.home_display_order', 'ASC');
            $builder->orderBy('P.id', 'DESC');

            $total = (int) $builder->countAllResults(false);
            $rows = $builder->limit($limit, $offset)->get()->getResultArray();

            $products = array_map(function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'product_name' => (string) ($row['product_name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'short_description' => (string) ($row['short_description'] ?? ''),
                    'min_price' => (float) ($row['min_price'] ?? 0),
                    'primary_image' => $row['primary_image'] ?? null,
                    'is_b2c_sale' => (int) ($row['is_b2c_sale'] ?? 0),
                ];
            }, $rows);

            return json_success([
                'products' => $products,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / max(1, $limit)),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2cSales::productsList ' . $e->getMessage());
            return json_error('Failed to load B2C products', 500);
        }
    }

    /**
     * POST /api/b2c/order/create
     */
    public function createOrder(): ResponseInterface
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                return json_error('Invalid request body', 400);
            }

            $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];
            $itemsIn = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

            $name = trim((string) ($customer['name'] ?? ''));
            $email = trim((string) ($customer['email'] ?? ''));
            $phone = trim((string) ($customer['phone'] ?? ''));
            $address1 = trim((string) ($customer['address1'] ?? $customer['address_line1'] ?? ''));
            $address2 = trim((string) ($customer['address2'] ?? $customer['address_line2'] ?? ''));
            $city = trim((string) ($customer['city'] ?? ''));
            $state = trim((string) ($customer['state'] ?? ''));
            $pincode = trim((string) ($customer['pincode'] ?? ''));

            if ($name === '' || $email === '' || $phone === '' || $address1 === '' || $city === '' || $pincode === '') {
                return json_error('Please fill all required customer and address fields', 400);
            }

            if (empty($itemsIn)) {
                return json_error('At least one product is required', 400);
            }

            $lineItems = [];
            $gross = 0.0;
            $gstTotal = 0.0;

            foreach ($itemsIn as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $variationId = !empty($row['variation_id']) ? (int) $row['variation_id'] : null;
                $qty = max(1, (int) ($row['qty'] ?? 1));

                if ($productId <= 0) {
                    continue;
                }

                $product = $this->productModel->getProductDetails($productId);
                if (empty($product) || (int) ($product['is_b2c_sale'] ?? 0) !== 1) {
                    return json_error('Product is not available for B2C sales: ID ' . $productId, 400);
                }

                if (($product['status'] ?? '') !== 'ACTIVE') {
                    return json_error('Product is not active: ' . ($product['product_name'] ?? $productId), 400);
                }

                $unitPrice = $this->resolveUnitPrice($product, $variationId);
                if ($unitPrice <= 0) {
                    return json_error('Invalid price for product: ' . ($product['product_name'] ?? $productId), 400);
                }

                $lineTotal = round($unitPrice * $qty, 2);
                $gstRate = (float) ($product['gst_rate'] ?? 0);
                $lineGst = $gstRate > 0 ? round($lineTotal * $gstRate / 100, 2) : 0.0;

                $gross += $lineTotal;
                $gstTotal += $lineGst;

                $lineItems[] = [
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'product_name' => (string) ($product['product_name'] ?? ''),
                    'sku' => $product['sku_number'] ?? $product['product_code'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            if (empty($lineItems)) {
                return json_error('No valid products in order', 400);
            }

            $paidAmount = round($gross + $gstTotal, 2);
            $orderNo = $this->b2cOrderModel->generateOrderNo();
            $now = date('Y-m-d H:i:s');

            $orderId = $this->b2cOrderModel->insert([
                'order_no' => $orderNo,
                'customer_name' => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'address_line1' => $address1,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode,
                'gross_amount' => round($gross, 2),
                'gst_amount' => round($gstTotal, 2),
                'discount_amount' => 0,
                'paid_amount' => $paidAmount,
                'pay_status' => 'pending',
                'pay_gateway_name' => 'razorpay',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$orderId) {
                return json_error('Failed to create order', 500);
            }

            $this->b2cOrderModel->insertItems((int) $orderId, $lineItems);

            $razorpay = new RazorpayService();
            if (!$razorpay->isConfigured()) {
                return json_error(
                    'Payment gateway is not configured. Add RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET to khaitan_api/.env (from Razorpay Dashboard → API Keys), then restart Apache.',
                    503
                );
            }

            $amountPaise = (int) round($paidAmount * 100);
            $rzOrder = $razorpay->createOrder($amountPaise, $orderNo, [
                'b2c_order_no' => $orderNo,
                'b2c_order_id' => (string) $orderId,
            ]);

            $this->b2cOrderModel->update((int) $orderId, [
                'razorpay_order_id' => $rzOrder['id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return json_success([
                'order' => [
                    'id' => (int) $orderId,
                    'order_no' => $orderNo,
                    'paid_amount' => $paidAmount,
                    'pay_status' => 'pending',
                ],
                'payment' => [
                    'key_id' => $razorpay->getKeyId(),
                    'order_id' => $rzOrder['id'],
                    'amount' => $amountPaise,
                    'currency' => 'INR',
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2cSales::createOrder ' . $e->getMessage());
            return json_error($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/b2c/payment/verify
     */
    public function verifyPayment(): ResponseInterface
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                return json_error('Invalid request body', 400);
            }

            $orderNo = trim((string) ($payload['order_no'] ?? ''));
            $razorpayOrderId = trim((string) ($payload['razorpay_order_id'] ?? ''));
            $razorpayPaymentId = trim((string) ($payload['razorpay_payment_id'] ?? ''));
            $razorpaySignature = trim((string) ($payload['razorpay_signature'] ?? ''));
            $paymentFailed = !empty($payload['payment_failed']);

            if ($orderNo === '') {
                return json_error('order_no is required', 400);
            }

            $order = $this->b2cOrderModel->getByOrderNo($orderNo);
            if (!$order) {
                return json_error('Order not found', 404);
            }

            if ($paymentFailed) {
                $this->b2cOrderModel->update((int) $order['id'], [
                    'pay_status' => 'failed',
                    'razorpay_order_id' => $razorpayOrderId ?: $order['razorpay_order_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return json_success(['order_no' => $orderNo, 'pay_status' => 'failed'], 'Payment marked as failed');
            }

            if ($razorpayOrderId === '' || $razorpayPaymentId === '' || $razorpaySignature === '') {
                return json_error('Payment verification data is incomplete', 400);
            }

            $razorpay = new RazorpayService();
            if (!$razorpay->verifySignature($razorpayOrderId, $razorpayPaymentId, $razorpaySignature)) {
                $this->b2cOrderModel->update((int) $order['id'], [
                    'pay_status' => 'failed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return json_error('Payment verification failed', 400);
            }

            $this->b2cOrderModel->update((int) $order['id'], [
                'pay_status' => 'paid',
                'razorpay_order_id' => $razorpayOrderId,
                'razorpay_payment_id' => $razorpayPaymentId,
                'razorpay_signature' => $razorpaySignature,
                'txn_id' => $razorpayPaymentId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return json_success([
                'order_no' => $orderNo,
                'pay_status' => 'paid',
            ], 'Payment successful');
        } catch (\Throwable $e) {
            log_message('error', 'B2cSales::verifyPayment ' . $e->getMessage());
            return json_error('Payment verification failed', 500);
        }
    }

    private function resolveUnitPrice(array $product, ?int $variationId): float
    {
        if ($variationId > 0) {
            $var = $this->productModel->db->table('product_variations')
                ->where('id', $variationId)
                ->where('product_id', (int) $product['id'])
                ->where('status', 'ACTIVE')
                ->get()
                ->getRowArray();

            if ($var) {
                $final = (float) ($var['final_price'] ?? 0);
                if ($final > 0) {
                    return $final;
                }
                $sale = (float) ($var['sale_price'] ?? 0);
                if ($sale > 0) {
                    return $sale;
                }
                $price = (float) ($var['price'] ?? 0);
                if ($price > 0) {
                    return $price;
                }
            }
        }

        foreach (['final_price', 'sale_price', 'product_price', 'mrp'] as $field) {
            $val = (float) ($product[$field] ?? 0);
            if ($val > 0) {
                return $val;
            }
        }

        return 0.0;
    }
}
