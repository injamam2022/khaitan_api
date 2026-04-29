<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Check ci_sessions Table Controller
 * 
 * Diagnostic endpoint to verify ci_sessions table exists and has correct structure.
 */
class CheckCiSessionsTable extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    /**
     * Check ci_sessions table structure and data
     */
    public function index()
    {
        $this->response->setContentType('application/json');
        
        $results = [
            'table_exists' => false,
            'table_structure' => [],
            'table_count' => 0,
            'sample_records' => [],
            'issues' => [],
            'recommendations' => []
        ];
        
        try {
            $db = \Config\Database::connect();
            
            // Check if table exists
            $tables = $db->listTables();
            $results['table_exists'] = in_array('ci_sessions', $tables);
            
            if (!$results['table_exists']) {
                $results['issues'][] = 'Table ci_sessions does not exist';
                $results['recommendations'][] = 'Run migration: php spark migrate';
                $results['recommendations'][] = 'Or create table manually using SQL from migration file';
                return json_error($results, 404);
            }
            
            // Get table structure
            $fields = $db->getFieldData('ci_sessions');
            $results['table_structure'] = array_map(function($field) {
                return [
                    'name' => $field->name,
                    'type' => $field->type,
                    'max_length' => $field->max_length ?? null,
                    'nullable' => $field->nullable ?? false,
                    'default' => $field->default ?? null,
                    'primary_key' => $field->primary_key ?? false
                ];
            }, $fields);
            
            // Check required columns
            $requiredColumns = ['id', 'ip_address', 'timestamp', 'data'];
            $existingColumns = array_column($results['table_structure'], 'name');
            $missingColumns = array_diff($requiredColumns, $existingColumns);
            
            if (!empty($missingColumns)) {
                $results['issues'][] = 'Missing required columns: ' . implode(', ', $missingColumns);
            }
            
            // Check column types
            foreach ($results['table_structure'] as $field) {
                if ($field['name'] === 'id' && $field['type'] !== 'varchar' && $field['max_length'] < 128) {
                    $results['issues'][] = 'Column "id" should be VARCHAR(128)';
                }
                if ($field['name'] === 'ip_address' && $field['type'] !== 'varchar' && $field['max_length'] < 45) {
                    $results['issues'][] = 'Column "ip_address" should be VARCHAR(45)';
                }
                if ($field['name'] === 'timestamp' && $field['type'] !== 'int') {
                    $results['issues'][] = 'Column "timestamp" should be INT(10) UNSIGNED';
                }
                if ($field['name'] === 'data' && $field['type'] !== 'blob') {
                    $results['issues'][] = 'Column "data" should be BLOB';
                }
            }
            
            // Check indexes
            $indexes = $db->getIndexData('ci_sessions');
            $hasPrimaryKey = false;
            $hasTimestampIndex = false;
            
            foreach ($indexes as $index) {
                if ($index->type === 'PRIMARY') {
                    $hasPrimaryKey = true;
                }
                if (in_array('timestamp', $index->fields)) {
                    $hasTimestampIndex = true;
                }
            }
            
            if (!$hasPrimaryKey) {
                $results['issues'][] = 'Missing PRIMARY KEY on id column';
            }
            if (!$hasTimestampIndex) {
                $results['issues'][] = 'Missing INDEX on timestamp column (for garbage collection)';
            }
            
            // Get record count
            $builder = $db->table('ci_sessions');
            $results['table_count'] = $builder->countAllResults(false);
            
            // Get sample records (latest 5)
            if ($results['table_count'] > 0) {
                $builder = $db->table('ci_sessions');
                $builder->orderBy('timestamp', 'DESC');
                $builder->limit(5);
                $sampleRecords = $builder->get()->getResultArray();
                
                $results['sample_records'] = array_map(function($record) {
                    return [
                        'id' => substr($record['id'], 0, 20) . '...',
                        'ip_address' => $record['ip_address'],
                        'timestamp' => $record['timestamp'],
                        'data_length' => strlen($record['data'] ?? ''),
                        'created_at' => date('Y-m-d H:i:s', $record['timestamp'])
                    ];
                }, $sampleRecords);
            }
            
            // Test insert (dry run)
            $testSessionId = 'test_' . time();
            $testData = [
                'id' => $testSessionId,
                'ip_address' => '127.0.0.1',
                'timestamp' => time(),
                'data' => serialize(['test' => 'data'])
            ];
            
            try {
                $builder = $db->table('ci_sessions');
                $builder->insert($testData);
                $results['insert_test'] = 'PASSED';
                
                // Clean up test record
                $builder = $db->table('ci_sessions');
                $builder->where('id', $testSessionId);
                $builder->delete();
            } catch (\Exception $e) {
                $results['insert_test'] = 'FAILED';
                $results['issues'][] = 'Insert test failed: ' . $e->getMessage();
            }
            
            // Summary
            if (empty($results['issues'])) {
                $results['status'] = 'OK';
                $results['message'] = 'ci_sessions table exists and has correct structure';
                return json_success($results, 'Table check passed');
            } else {
                $results['status'] = 'ISSUES_FOUND';
                $results['message'] = 'Table exists but has issues';
                return json_error($results, 200); // 200 because table exists, just has issues
            }
            
        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            $results['trace'] = $e->getTraceAsString();
            log_message('error', 'CheckCiSessionsTable::index - Exception: ' . $e->getMessage());
            return json_error($results, 500);
        }
    }
    
    /**
     * Create ci_sessions table if it doesn't exist
     */
    public function create()
    {
        $this->response->setContentType('application/json');
        
        try {
            $db = \Config\Database::connect();
            
            // Check if table already exists
            $tables = $db->listTables();
            if (in_array('ci_sessions', $tables)) {
                return json_error(['message' => 'Table ci_sessions already exists'], 400);
            }
            
            // Create table using CodeIgniter's Forge
            $forge = \Config\Database::forge();
            
            $fields = [
                'id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 128,
                    'null' => false,
                ],
                'ip_address' => [
                    'type' => 'VARCHAR',
                    'constraint' => 45,
                    'null' => false,
                ],
                'timestamp' => [
                    'type' => 'INT',
                    'constraint' => 10,
                    'unsigned' => true,
                    'default' => 0,
                    'null' => false,
                ],
                'data' => [
                    'type' => 'BLOB',
                    'null' => true,
                ],
            ];
            
            $forge->addField($fields);
            $forge->addPrimaryKey('id');
            $forge->addKey('timestamp');
            $forge->createTable('ci_sessions', true);
            
            log_message('info', 'CheckCiSessionsTable::create - Created ci_sessions table');
            
            return json_success([
                'message' => 'ci_sessions table created successfully',
                'table_name' => 'ci_sessions'
            ], 'Table created');
            
        } catch (\Exception $e) {
            log_message('error', 'CheckCiSessionsTable::create - Exception: ' . $e->getMessage());
            return json_error([
                'message' => 'Failed to create table: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Fix ci_sessions table structure
     */
    public function fix()
    {
        $this->response->setContentType('application/json');
        
        try {
            $db = \Config\Database::connect();
            $forge = \Config\Database::forge();
            
            // Check if table exists
            $tables = $db->listTables();
            if (!in_array('ci_sessions', $tables)) {
                return json_error(['message' => 'Table ci_sessions does not exist. Use /check-ci-sessions/create first.'], 404);
            }
            
            $fixes = [];
            
            // Get current structure
            $fields = $db->getFieldData('ci_sessions');
            $existingColumns = array_column($fields, 'name');
            
            // Check and fix columns
            $requiredColumns = [
                'id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                'timestamp' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0, 'null' => false],
                'data' => ['type' => 'BLOB', 'null' => true]
            ];
            
            foreach ($requiredColumns as $columnName => $columnDef) {
                if (!in_array($columnName, $existingColumns)) {
                    // Add missing column
                    $forge->addColumn('ci_sessions', [
                        $columnName => $columnDef
                    ]);
                    $fixes[] = "Added column: {$columnName}";
                }
            }
            
            // Check indexes
            $indexes = $db->getIndexData('ci_sessions');
            $hasPrimaryKey = false;
            $hasTimestampIndex = false;
            
            foreach ($indexes as $index) {
                if ($index->type === 'PRIMARY' && in_array('id', $index->fields)) {
                    $hasPrimaryKey = true;
                }
                if (in_array('timestamp', $index->fields) && $index->type !== 'PRIMARY') {
                    $hasTimestampIndex = true;
                }
            }
            
            if (!$hasPrimaryKey) {
                $forge->addPrimaryKey('id', 'ci_sessions');
                $fixes[] = "Added PRIMARY KEY on id";
            }
            
            if (!$hasTimestampIndex) {
                $forge->addKey('timestamp', false, false, 'ci_sessions');
                $fixes[] = "Added INDEX on timestamp";
            }
            
            if (empty($fixes)) {
                return json_success([
                    'message' => 'Table structure is already correct',
                    'fixes_applied' => []
                ], 'No fixes needed');
            }
            
            log_message('info', 'CheckCiSessionsTable::fix - Applied fixes: ' . implode(', ', $fixes));
            
            return json_success([
                'message' => 'Table structure fixed',
                'fixes_applied' => $fixes
            ], 'Fixes applied successfully');
            
        } catch (\Exception $e) {
            log_message('error', 'CheckCiSessionsTable::fix - Exception: ' . $e->getMessage());
            return json_error([
                'message' => 'Failed to fix table: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
