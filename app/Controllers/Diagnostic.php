<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Diagnostic Controller
 * 
 * Comprehensive database and system diagnostics for production debugging.
 * Tests database connection, inserts, updates, sessions, and model operations.
 */
class Diagnostic extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Comprehensive diagnostic endpoint
     * Tests: Database connection, insert operations, session persistence
     */
    public function index()
    {
        $this->response->setContentType('application/json');
        
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tests' => []
        ];
        
        // Test 1: Database Connection
        try {
            $db = \Config\Database::connect();
            $query = $db->query("SELECT 1 as test, DATABASE() as db_name, USER() as db_user");
            $row = $query->getRow();
            
            $results['tests']['database_connection'] = [
                'status' => 'PASS',
                'database' => $row->db_name ?? 'unknown',
                'user' => $row->db_user ?? 'unknown',
                'hostname' => $db->hostname ?? 'unknown',
                'driver' => $db->DBDriver ?? 'unknown'
            ];
        } catch (\Exception $e) {
            $results['tests']['database_connection'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
            return json_error($results, 500);
        }
        
        // Test 2: Test Table Exists
        try {
            $db = \Config\Database::connect();
            $tables = $db->listTables();
            $requiredTables = ['admin_login', 'products', 'ci_sessions'];
            $missingTables = [];
            
            foreach ($requiredTables as $table) {
                if (!in_array($table, $tables)) {
                    $missingTables[] = $table;
                }
            }
            
            $results['tests']['tables'] = [
                'status' => empty($missingTables) ? 'PASS' : 'FAIL',
                'total_tables' => count($tables),
                'required_tables' => $requiredTables,
                'missing_tables' => $missingTables
            ];
        } catch (\Exception $e) {
            $results['tests']['tables'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
        }
        
        // Test 3: Test Database INSERT Operation
        try {
            $db = \Config\Database::connect();
            $testTable = 'diagnostic_test';
            
            // Create test table if it doesn't exist
            $db->query("CREATE TABLE IF NOT EXISTS `{$testTable}` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `test_data` VARCHAR(255),
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Test INSERT
            $testData = 'diagnostic_test_' . time();
            $insertResult = $db->table($testTable)->insert([
                'test_data' => $testData
            ]);
            
            if ($insertResult) {
                $insertId = $db->insertID();
                
                // Verify data was inserted
                $verify = $db->table($testTable)->where('id', $insertId)->get()->getRowArray();
                
                // Clean up test data
                $db->table($testTable)->where('id', $insertId)->delete();
                
                $results['tests']['database_insert'] = [
                    'status' => ($verify && $verify['test_data'] === $testData) ? 'PASS' : 'FAIL',
                    'insert_id' => $insertId,
                    'verified' => !empty($verify),
                    'message' => ($verify && $verify['test_data'] === $testData) 
                        ? 'Insert and verify successful' 
                        : 'Insert succeeded but verification failed'
                ];
            } else {
                $dbError = $db->error();
                $results['tests']['database_insert'] = [
                    'status' => 'FAIL',
                    'error' => $dbError['message'] ?? 'Insert returned false',
                    'code' => $dbError['code'] ?? 'unknown'
                ];
            }
        } catch (\Exception $e) {
            $results['tests']['database_insert'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        // Test 4: Test Model INSERT Operation
        try {
            $productModel = new \App\Models\ProductModel();
            $db = \Config\Database::connect();
            
            // CRITICAL: Get a valid brand_id to satisfy foreign key constraint
            // Foreign key requires brand_id to reference an existing id in product_brand table
            $brandList = $productModel->getProductBrandList('ACTIVE');
            
            if (empty($brandList)) {
                // No brands exist - create a temporary test brand for the diagnostic
                $testBrandData = [
                    'brand' => 'Diagnostic Test Brand',
                    'status' => 'ACTIVE',
                    'created_id' => 1,
                    'created_on' => date('Y-m-d H:i:s')
                ];
                $brandId = $productModel->insertProductBrand($testBrandData);
                
                if (!$brandId) {
                    throw new \Exception('Failed to create test brand for diagnostic');
                }
                
                $createdTestBrand = true;
            } else {
                $brandId = (int)$brandList[0]['id'];
                $createdTestBrand = false;
            }
            
            // Test data (minimal required fields)
            // CRITICAL: Include brand_id to satisfy foreign key constraint
            $testProduct = [
                'product_code' => 'TEST_' . time(),
                'product_name' => 'Diagnostic Test Product',
                'brand_id' => $brandId, // Use valid brand_id
                'mrp' => 100.00,
                'sale_price' => 90.00,
                'final_price' => 90.00,
                'stock_quantity' => 1,
                'in_stock' => 1,
                'status' => 'ACTIVE',
                'product_type' => 'NA',
                'created_id' => 1,
                'created_on' => date('Y-m-d H:i:s')
            ];
            
            $productId = $productModel->insertProduct($testProduct);
            
            if ($productId > 0) {
                // Verify product exists
                $verify = $productModel->getProductDetails($productId);
                
                // Clean up test product
                $productModel->updateProduct($productId, ['status' => 'DELETED']);
                
                // Clean up test brand if we created it
                if ($createdTestBrand && $brandId > 0) {
                    $db->table('product_brand')->where('id', $brandId)->update(['status' => 'DELETED']);
                }
                
                $results['tests']['model_insert'] = [
                    'status' => !empty($verify) ? 'PASS' : 'FAIL',
                    'product_id' => $productId,
                    'brand_id_used' => $brandId,
                    'test_brand_created' => $createdTestBrand,
                    'verified' => !empty($verify),
                    'message' => !empty($verify) 
                        ? 'Model insert and verify successful' 
                        : 'Model insert succeeded but verification failed'
                ];
            } else {
                $modelErrors = $productModel->errors();
                $dbError = $db->error();
                
                // Clean up test brand if we created it
                if ($createdTestBrand && $brandId > 0) {
                    $db->table('product_brand')->where('id', $brandId)->update(['status' => 'DELETED']);
                }
                
                $results['tests']['model_insert'] = [
                    'status' => 'FAIL',
                    'model_errors' => $modelErrors,
                    'db_error' => $dbError['message'] ?? null,
                    'db_code' => $dbError['code'] ?? null,
                    'brand_id_attempted' => $brandId
                ];
            }
        } catch (\Exception $e) {
            $results['tests']['model_insert'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        // Test 5: Session Persistence
        try {
            $session = \Config\Services::session();
            
            // Set test session data
            $testKey = 'diagnostic_test_' . time();
            $testValue = 'test_value_' . rand(1000, 9999);
            $session->set($testKey, $testValue);
            
            // Immediately retrieve
            $retrieved = $session->get($testKey);
            
            // Get session ID
            $sessionId = $session->session_id;
            
            // Check session file exists (for file-based sessions)
            $sessionPath = WRITEPATH . 'session/';
            $sessionFile = $sessionPath . 'ci_session_' . $sessionId;
            $fileExists = file_exists($sessionFile);
            
            $results['tests']['session_persistence'] = [
                'status' => ($retrieved === $testValue) ? 'PASS' : 'FAIL',
                'session_id' => $sessionId,
                'test_key' => $testKey,
                'test_value_set' => $testValue,
                'test_value_retrieved' => $retrieved,
                'match' => ($retrieved === $testValue),
                'session_file_exists' => $fileExists,
                'session_path' => $sessionPath
            ];
            
            // Clean up
            $session->remove($testKey);
        } catch (\Exception $e) {
            $results['tests']['session_persistence'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
        }
        
        // Test 6: Login Flow Simulation
        try {
            $profileModel = new \App\Models\ProfileModel();
            $db = \Config\Database::connect();
            
            // Check if admin_login table has any active users
            $builder = $db->table('admin_login');
            $builder->where('row_status', 'ACTIVE');
            $userCount = $builder->countAllResults();
            
            $results['tests']['login_flow'] = [
                'status' => $userCount > 0 ? 'PASS' : 'WARNING',
                'active_users' => $userCount,
                'message' => $userCount > 0 
                    ? 'Active admin users found - login should work' 
                    : 'No active admin users found - login will fail'
            ];
        } catch (\Exception $e) {
            $results['tests']['login_flow'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
        }
        
        // Determine overall status
        $hasFailures = false;
        foreach ($results['tests'] as $test) {
            if (isset($test['status']) && $test['status'] === 'FAIL') {
                $hasFailures = true;
                break;
            }
        }
        
        if ($hasFailures) {
            // Use json_response directly to pass data as $data parameter, not $message
            return json_response($results, 500, 'Some diagnostic tests failed', false);
        } else {
            return json_success($results, 'All diagnostic tests passed');
        }
    }
}
