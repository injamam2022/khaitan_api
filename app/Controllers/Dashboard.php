<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Dashboard extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    public function index()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }

        $db = \Config\Database::connect();

        $stats = [
            'user_count' => 0,
            'category_count' => 0,
            'product_count' => 0,
            'active_products' => 0,
            'brand_count' => 0,
            'order_count' => 0,
            'paid_amount' => null,
        ];
        try {
            $row = $db->query("SELECT 
                (SELECT COUNT(*) FROM users) AS user_count,
                (SELECT COUNT(*) FROM product_category) AS category_count,
                (SELECT COUNT(*) FROM products) AS product_count,
                (SELECT COUNT(*) FROM products WHERE status = 'ACTIVE') AS active_products,
                (SELECT COUNT(*) FROM product_brand) AS brand_count,
                (SELECT COUNT(*) FROM orders) AS order_count,
                (SELECT COALESCE(SUM(paid_amount), 0) FROM orders) AS paid_amount
            ")->getRowArray();
            if ($row) {
                $stats = [
                    'user_count' => (int) ($row['user_count'] ?? 0),
                    'category_count' => (int) ($row['category_count'] ?? 0),
                    'product_count' => (int) ($row['product_count'] ?? 0),
                    'active_products' => (int) ($row['active_products'] ?? 0),
                    'brand_count' => (int) ($row['brand_count'] ?? 0),
                    'order_count' => (int) ($row['order_count'] ?? 0),
                    'paid_amount' => isset($row['paid_amount']) ? (float) $row['paid_amount'] : 0,
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard stats query failed: ' . $e->getMessage());
        }

        $recent_orders = [];
        try {
            $recent_orders = $db->query("
                SELECT 
                    o.id,
                    o.order_no,
                    o.paid_amount,
                    o.paid_amount AS total_amount,
                    o.status AS order_status,
                    o.pay_status,
                    o.created_at,
                    o.created_date,
                    COALESCE(u.fullname, 'Unknown Customer') AS customer_name,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.pay_status = 'SUCCESS'
                ORDER BY o.id DESC
                LIMIT 5
            ")->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard recent_orders query failed: ' . $e->getMessage());
        }

        $top_sellers = [];
        try {
            $top_sellers = $db->query("SELECT sum(oi.qty) as qty, p.product_name, p.sku_number, p.product_code, p.in_stock from order_items as oi inner join products as p on p.id=oi.product_id inner join orders as o on o.id=oi.order_id where o.pay_status='SUCCESS' group by p.id order by qty desc limit 5")->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard top_sellers query failed: ' . $e->getMessage());
        }

        return json_success([
            'stats' => $stats,
            'recent_orders' => $recent_orders,
            'top_sellers' => $top_sellers
        ]);
    }

    /**
     * Get revenue data for chart
     * Supports: 7d (daily), 30d (weekly), or custom date range
     */
    public function revenue()
    {
        // Check authentication
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        
        $period = $this->request->getGet('period') ?: '7d'; // 7d, 30d, or custom
        $start_date = $this->request->getGet('start_date');
        $end_date = $this->request->getGet('end_date');
        
        $data = [];
        $currentPeriodRevenue = 0;
        $previousPeriodRevenue = 0;
        $percentageChange = 0;
        
        try {
            $db = \Config\Database::connect();
        
        if ($period === 'custom' && $start_date && $end_date) {
            // Custom date range - group by day (use COALESCE for created_at/created_date compatibility)
            $query = "SELECT 
                DATE(COALESCE(created_at, created_date)) as date,
                SUM(COALESCE(paid_amount, 0)) as revenue,
                COUNT(*) as orders
            FROM orders 
            WHERE pay_status = 'SUCCESS' 
            AND DATE(COALESCE(created_at, created_date)) >= ? 
            AND DATE(COALESCE(created_at, created_date)) <= ?
            GROUP BY DATE(COALESCE(created_at, created_date))
            ORDER BY date ASC";
            
            $results = $db->query($query, [$start_date, $end_date])->getResultArray();
            
            foreach ($results as $row) {
                $date = new \DateTime($row['date']);
                $data[] = [
                    'name' => $date->format('M d'),
                    'revenue' => floatval($row['revenue']),
                    'orders' => intval($row['orders'])
                ];
            }
            
            // Calculate current period revenue
            $currentQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND DATE(COALESCE(created_at, created_date)) >= ? 
                AND DATE(COALESCE(created_at, created_date)) <= ?";
            $currentResult = $db->query($currentQuery, [$start_date, $end_date])->getRowArray();
            $currentPeriodRevenue = floatval($currentResult['revenue'] ?? 0);
            
            // Calculate previous period (same duration before start date)
            $startDateObj = new \DateTime($start_date);
            $endDateObj = new \DateTime($end_date);
            $daysDiff = $startDateObj->diff($endDateObj)->days;
            
            $prevEndDate = date('Y-m-d', strtotime($start_date . ' -1 day'));
            $prevStartDate = date('Y-m-d', strtotime($prevEndDate . ' -' . $daysDiff . ' days'));
            
            $previousQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND DATE(COALESCE(created_at, created_date)) >= ? 
                AND DATE(COALESCE(created_at, created_date)) <= ?";
            $previousResult = $db->query($previousQuery, [$prevStartDate, $prevEndDate])->getRowArray();
            $previousPeriodRevenue = floatval($previousResult['revenue'] ?? 0);
            
            // Calculate percentage change
            if ($previousPeriodRevenue > 0) {
                $percentageChange = (($currentPeriodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100;
            } elseif ($currentPeriodRevenue > 0) {
                $percentageChange = 100; // 100% increase if previous was 0
            }
        } elseif ($period === '30d') {
            // Last 30 days - group by week (4 weeks)
            $query = "SELECT 
                YEARWEEK(COALESCE(created_at, created_date), 1) as week,
                SUM(COALESCE(paid_amount, 0)) as revenue,
                COUNT(*) as orders,
                MIN(DATE(COALESCE(created_at, created_date))) as week_start
            FROM orders 
            WHERE pay_status = 'SUCCESS' 
            AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY YEARWEEK(COALESCE(created_at, created_date), 1)
            ORDER BY week ASC";
            
            $results = $db->query($query)->getResultArray();
            
            // Ensure we have exactly 4 weeks of data
            $weekNum = 1;
            
            foreach ($results as $row) {
                $data[] = [
                    'name' => 'Week ' . $weekNum,
                    'revenue' => floatval($row['revenue']),
                    'orders' => intval($row['orders'])
                ];
                $weekNum++;
            }
            
            // Fill missing weeks with zero values if needed
            while (count($data) < 4) {
                $data[] = [
                    'name' => 'Week ' . $weekNum,
                    'revenue' => 0,
                    'orders' => 0
                ];
                $weekNum++;
            }
            
            // Limit to 4 weeks
            $data = array_slice($data, -4);
            
            // Calculate current period (last 30 days) and previous period (30-60 days ago) revenue
            $currentQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $currentResult = $db->query($currentQuery)->getRowArray();
            $currentPeriodRevenue = floatval($currentResult['revenue'] ?? 0);
            
            $previousQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                AND COALESCE(created_at, created_date) < DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $previousResult = $db->query($previousQuery)->getRowArray();
            $previousPeriodRevenue = floatval($previousResult['revenue'] ?? 0);
            
            // Calculate percentage change
            if ($previousPeriodRevenue > 0) {
                $percentageChange = (($currentPeriodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100;
            } elseif ($currentPeriodRevenue > 0) {
                $percentageChange = 100; // 100% increase if previous was 0
            }
        } else {
            // Default: Last 7 days - group by day
            $query = "SELECT 
                DATE(COALESCE(created_at, created_date)) as date,
                DAYNAME(COALESCE(created_at, created_date)) as day_name,
                SUM(COALESCE(paid_amount, 0)) as revenue,
                COUNT(*) as orders
            FROM orders 
            WHERE pay_status = 'SUCCESS' 
            AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(COALESCE(created_at, created_date))
            ORDER BY date ASC";
            
            $result = $db->query($query);
            $results = ($result !== false) ? $result->getResultArray() : [];
            $dayNames = ['Sunday' => 'Sun', 'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat'];
            
            foreach ($results as $row) {
                $dayName = isset($dayNames[$row['day_name']]) ? $dayNames[$row['day_name']] : substr($row['day_name'], 0, 3);
                $data[] = [
                    'name' => $dayName,
                    'revenue' => floatval($row['revenue']),
                    'orders' => intval($row['orders'])
                ];
            }
            
            // Fill in missing days with zero values
            $allDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $existingDays = array_column($data, 'name');
            $missingDays = array_diff($allDays, $existingDays);
            
            foreach ($missingDays as $day) {
                $data[] = [
                    'name' => $day,
                    'revenue' => 0,
                    'orders' => 0
                ];
            }
            
            // Sort by day order
            $dayOrder = array_flip($allDays);
            usort($data, function($a, $b) use ($dayOrder) {
                return ($dayOrder[$a['name']] ?? 999) - ($dayOrder[$b['name']] ?? 999);
            });
            
            // Calculate current period (last 7 days) and previous period (7-14 days ago) revenue
            $currentQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $currentResult = $db->query($currentQuery)->getRowArray();
            $currentPeriodRevenue = floatval($currentResult['revenue'] ?? 0);
            
            $previousQuery = "SELECT SUM(COALESCE(paid_amount, 0)) as revenue FROM orders 
                WHERE pay_status = 'SUCCESS' 
                AND COALESCE(created_at, created_date) >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                AND COALESCE(created_at, created_date) < DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $previousResult = $db->query($previousQuery)->getRowArray();
            $previousPeriodRevenue = floatval($previousResult['revenue'] ?? 0);
            
            // Calculate percentage change
            if ($previousPeriodRevenue > 0) {
                $percentageChange = (($currentPeriodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100;
            } elseif ($currentPeriodRevenue > 0) {
                $percentageChange = 100; // 100% increase if previous was 0
            }
        }
        
            return json_success([
                'data' => $data,
                'total_revenue' => $currentPeriodRevenue,
                'percentage_change' => round($percentageChange, 2),
                'previous_revenue' => $previousPeriodRevenue
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard revenue query failed: ' . $e->getMessage());
            return json_success([
                'data' => [],
                'total_revenue' => 0,
                'percentage_change' => 0,
                'previous_revenue' => 0
            ]);
        }
    }
}
