<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Session Test Controller
 * 
 * Diagnostic endpoint to test session database persistence.
 * This helps verify that sessions are being saved to ci_sessions table.
 */
class SessionTest extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Test session write to database
     * 
     * This endpoint:
     * 1. Sets test data in session
     * 2. Explicitly saves session
     * 3. Verifies session exists in database
     * 4. Returns diagnostic information
     */
    public function index()
    {
        $this->response->setContentType('application/json');
        
        $session = \Config\Services::session();
        $sessionId = $session->session_id;
        
        $results = [
            'session_id' => $sessionId,
            'driver' => get_class($session->getDriver()),
            'is_database_handler' => $session->getDriver() instanceof \CodeIgniter\Session\Handlers\DatabaseHandler,
            'tests' => []
        ];
        
        // Test 1: Check if session driver is DatabaseHandler
        $results['tests']['driver_check'] = [
            'name' => 'Session Driver Check',
            'expected' => 'CodeIgniter\Session\Handlers\DatabaseHandler',
            'actual' => get_class($session->getDriver()),
            'passed' => $session->getDriver() instanceof \CodeIgniter\Session\Handlers\DatabaseHandler
        ];
        
        if (!$results['tests']['driver_check']['passed']) {
            $results['error'] = 'Session driver is not DatabaseHandler. Check app/Config/Session.php';
            return json_error($results, 500);
        }
        
        // Test 2: Set test data in session
        $testData = [
            'test_key' => 'test_value_' . time(),
            'test_timestamp' => time(),
            'test_user_id' => 999
        ];
        
        $session->set('test_data', $testData);
        $session->markAsDirty();
        $session->save();
        
        $results['tests']['session_set'] = [
            'name' => 'Session Set Test',
            'test_data' => $testData,
            'passed' => true
        ];
        
        // Test 3: Verify session exists in database
        $db = \Config\Database::connect();
        $builder = $db->table('ci_sessions');
        $builder->where('id', $sessionId);
        $dbSession = $builder->get()->getRowArray();
        
        $results['tests']['database_check'] = [
            'name' => 'Database Session Check',
            'session_id' => $sessionId,
            'found_in_db' => !empty($dbSession),
            'db_record' => $dbSession ? [
                'id' => $dbSession['id'],
                'ip_address' => $dbSession['ip_address'],
                'timestamp' => $dbSession['timestamp'],
                'data_length' => strlen($dbSession['data'] ?? '')
            ] : null,
            'passed' => !empty($dbSession)
        ];
        
        // Test 4: Verify session data contains test data
        if (!empty($dbSession)) {
            $sessionData = unserialize($dbSession['data']);
            $hasTestData = isset($sessionData['test_data']) && 
                          $sessionData['test_data']['test_key'] === $testData['test_key'];
            
            $results['tests']['data_verification'] = [
                'name' => 'Session Data Verification',
                'has_test_data' => $hasTestData,
                'session_data_keys' => array_keys($sessionData ?? []),
                'passed' => $hasTestData
            ];
        } else {
            $results['tests']['data_verification'] = [
                'name' => 'Session Data Verification',
                'passed' => false,
                'error' => 'Cannot verify data - session not found in database'
            ];
        }
        
        // Test 5: Check table structure
        $tableInfo = $db->query("DESCRIBE ci_sessions")->getResultArray();
        $results['tests']['table_structure'] = [
            'name' => 'Table Structure Check',
            'columns' => array_column($tableInfo, 'Field'),
            'expected_columns' => ['id', 'ip_address', 'timestamp', 'data'],
            'passed' => count(array_intersect(['id', 'ip_address', 'timestamp', 'data'], array_column($tableInfo, 'Field'))) === 4
        ];
        
        // Summary
        $allPassed = true;
        foreach ($results['tests'] as $test) {
            if (isset($test['passed']) && !$test['passed']) {
                $allPassed = false;
                break;
            }
        }
        
        $results['summary'] = [
            'all_tests_passed' => $allPassed,
            'tests_run' => count($results['tests']),
            'tests_passed' => count(array_filter($results['tests'], fn($t) => isset($t['passed']) && $t['passed']))
        ];
        
        // Clean up test data
        $session->remove('test_data');
        $session->save();
        
        if ($allPassed) {
            return json_success($results, 'All session tests passed');
        } else {
            return json_error($results, 500);
        }
    }
}
