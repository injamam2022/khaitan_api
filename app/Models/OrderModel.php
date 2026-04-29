<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderModel extends Model
{
    protected $table = 'orders';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'user_id', 'order_no', 'address_id', 'total_amount', 'status', 'pay_status', 'state',
        'remarks', 'completed_date',
        // EasyEcom integration fields
        'awb_number', 'easyecom_order_id', 'easyecom_sync_status',
        // Phase 2: Delivery tracking fields (Delhivery via EasyEcom)
        'courier_name', 'shipment_status', 'fulfillment_status', 'label_url', 'tracking_url',
        'shipped_at', 'delivered_at', 'rto_at', 'cancelled_at',
        'created_date', 'created_at', 'updated_at',
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    public function create_order($order_data, $cart)
    {
        // Use model's database connection for transaction consistency
        $this->db->transStart();

        // Insert order
        $builder = $this->db->table('orders');
        $builder->insert($order_data);
        $order_id = $this->db->insertID();

        // Insert order items
        foreach ($cart as $c) {
            $order_item = [
                'user_id' => $order_data['user_id'],
                'order_id' => $order_id,
                'product_id' => $c['product_id'],
                'sale_price' => $c['sale_price'],
                'mrp_price' => $c['mrp_price'],
                'sale_unit' => $c['sale_unit'],
                'qty' => $c['qty'],
                'total_mrp_price' => $c['total_mrp_price'],
                'total_sale_price' => $c['total_sale_price'],
                'discount_amount' => $c['discount_amount'],
                'created_date' => $order_data['created_date'],
                'created_at' => $order_data['created_at']
            ];
            
            $builder = $this->db->table('order_items');
            $builder->insert($order_item);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return false;
        }

        // Get created order
        $builder = $this->db->table('orders');
        $builder->where('id', $order_id);
        $result = $builder->get()->getRowArray();

        if (!empty($result)) {
            return (object)$result;
        }

        return false;
    }

    public function getOrderList()
    {
        $builder = $this->db->table('orders as o');
        $builder->select('o.*, u.fullname as user_name, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count');
        $builder->join('users as u', 'u.id = o.user_id', 'inner');
        $builder->orderBy('o.id', 'DESC');

        return $builder->get()->getResultArray();
    }

    public function getOrderByUser($user_id)
    {
        $builder = $this->db->table('orders');
        $builder->where('user_id', (int)$user_id);
        $builder->orderBy('id', 'DESC');

        return $builder->get()->getResultArray();
    }

    public function getOrderItemsByUser($order_id, $user_id)
    {
        $builder = $this->db->table('order_items as it');
        $builder->select("p.product_code, p.product_name, p.sku_number, p.category_id, p.subcategory_id, p.brand_id, (SELECT im.image from product_image as im where im.product_id=p.id and im.status='ACTIVE' order by im.display_order asc, im.id asc limit 1) as primary_image, it.*");
        $builder->join('products as p', 'p.id = it.product_id', 'inner');
        $builder->where('it.user_id', (int)$user_id);
        $builder->where('it.order_id', (int)$order_id);

        return $builder->get()->getResultArray();
    }

    public function orderDetails($order_id)
    {
        $builder = $this->db->table('orders');
        $builder->where('id', (int)$order_id);

        $result = $builder->get()->getRowArray();
        return !empty($result) ? (object)$result : null;
    }

    public function update_order($where, $data)
    {
        $builder = $this->db->table('orders');

        if (is_array($where)) {
            foreach ($where as $key => $value) {
                $builder->where($key, $value);
            }
        } else {
            $builder->where($where);
        }

        return $builder->update($data);
    }

    public function getOrderDetails($order_no)
    {
        // Use LEFT JOIN for address so we still return order + items when address is missing
        $builder = $this->db->table('orders as bk');
        $builder->select('bk.*, u.fullname as user_fullname, ad.fullname, ad.email, ad.mobile, ad.city, ad.state, ad.pincode, ad.address1, ad.address2');
        $builder->join('users as u', 'u.id = bk.user_id', 'inner');
        $builder->join('user_saved_address as ad', 'ad.id = bk.address_id', 'left');
        $builder->where('bk.order_no', $order_no);

        $res = [];
        $res['od'] = $builder->get()->getRowArray();

        if (empty($res['od'])) {
            return $res;
        }

        // Ensure fullname for display when address is missing (use user name)
        if (empty($res['od']['fullname']) && !empty($res['od']['user_fullname'])) {
            $res['od']['fullname'] = $res['od']['user_fullname'];
        }

        // Get order items (always return array)
        $builder = $this->db->table('order_items as it');
        $builder->select("p.product_code, p.product_name, p.sku_number, p.category_id, p.subcategory_id, p.brand_id, (SELECT im.image from product_image as im where im.product_id=p.id and im.status='ACTIVE' order by im.display_order asc, im.id asc limit 1) as primary_image, it.*");
        $builder->join('products as p', 'p.id = it.product_id', 'inner');
        $builder->where('it.order_id', $res['od']['id']);

        $res['item'] = $builder->get()->getResultArray();
        if (!is_array($res['item'])) {
            $res['item'] = [];
        }

        return $res;
    }

    /**
     * Mark pending orders as failed if they are older than specified minutes
     * 
     * @param int $minutes Number of minutes after which to mark orders as failed (default: 5)
     * @return array Result with count of updated orders
     */
    public function markPendingOrdersAsFailed($minutes = 5)
    {
        // Validate minutes parameter
        if (!is_numeric($minutes) || $minutes < 1) {
            log_message('error', 'Invalid minutes parameter: ' . $minutes);
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Invalid minutes parameter. Must be a positive number.'
            ];
        }
        
        // Calculate the cutoff time (current time minus specified minutes)
        // Using database timezone-aware calculation
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
        
        // Update orders that are still PENDING and older than cutoff time
        // Using atomic UPDATE with WHERE conditions to avoid race conditions
        $builder = $this->db->table('orders');
        $builder->where('status', 'PENDING');
        $builder->where('pay_status', 'PENDING');
        $builder->where('created_at <=', $cutoffTime);
        
        $updateData = [
            'status' => 'CANCELLED',
            'pay_status' => 'FAILED'
        ];
        
        // Execute update
        if (!$builder->update($updateData)) {
            $error = $this->db->error();
            log_message('error', 'Failed to mark pending orders as failed: ' . json_encode($error));
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Failed to update orders: ' . ($error['message'] ?? 'Unknown error')
            ];
        }
        
        $affectedRows = $this->db->affectedRows();
        
        if ($affectedRows > 0) {
            log_message('info', "Marked {$affectedRows} pending order(s) as failed (older than {$minutes} minutes)");
        }
        
        return [
            'success' => true,
            'updated_count' => $affectedRows,
            'message' => $affectedRows > 0 
                ? "Successfully marked {$affectedRows} order(s) as failed" 
                : 'No pending orders found to update'
        ];
    }
}
