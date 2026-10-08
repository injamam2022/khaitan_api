<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class B2cSalesTables extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('is_b2c_sale', 'products')) {
            $this->forge->addColumn('products', [
                'is_b2c_sale' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'featured',
                ],
            ]);
        }

        if (!$this->db->tableExists('b2c_orders')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'order_no' => ['type' => 'VARCHAR', 'constraint' => 32],
                'customer_name' => ['type' => 'VARCHAR', 'constraint' => 255],
                'customer_email' => ['type' => 'VARCHAR', 'constraint' => 255],
                'customer_phone' => ['type' => 'VARCHAR', 'constraint' => 20],
                'address_line1' => ['type' => 'VARCHAR', 'constraint' => 500],
                'address_line2' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'city' => ['type' => 'VARCHAR', 'constraint' => 100],
                'state' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'pincode' => ['type' => 'VARCHAR', 'constraint' => 20],
                'gross_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'gst_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'discount_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'paid_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'pay_status' => ['type' => 'ENUM', 'constraint' => ['pending', 'paid', 'failed', 'cancelled'], 'default' => 'pending'],
                'pay_gateway_name' => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'razorpay'],
                'razorpay_order_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'razorpay_payment_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'razorpay_signature' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'txn_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('order_no');
            $this->forge->createTable('b2c_orders');
        }

        if (!$this->db->tableExists('b2c_order_items')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'b2c_order_id' => ['type' => 'INT', 'unsigned' => true],
                'product_id' => ['type' => 'INT', 'unsigned' => true],
                'variation_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'product_name' => ['type' => 'VARCHAR', 'constraint' => 500],
                'sku' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'qty' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'unit_price' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'line_total' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('b2c_order_id');
            $this->forge->createTable('b2c_order_items');
        }
    }

    public function down()
    {
        $this->forge->dropTable('b2c_order_items', true);
        $this->forge->dropTable('b2c_orders', true);
        if ($this->db->fieldExists('is_b2c_sale', 'products')) {
            $this->forge->dropColumn('products', 'is_b2c_sale');
        }
    }
}
