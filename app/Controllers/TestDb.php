<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;

class TestDb extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        try {
            $db = \Config\Database::connect();
            
            // Get database config
            $config = config('Database');
            $default = $config->default;
            
            // Test connection
            $result = $db->query('SELECT DATABASE() as db_name, USER() as db_user, VERSION() as db_version');
            $row = $result->getRow();
            
            // Get table count
            $tablesResult = $db->query('SHOW TABLES');
            $tables = [];
            if ($tablesResult) {
                foreach ($tablesResult->getResultArray() as $tableRow) {
                    $tables[] = array_values($tableRow)[0];
                }
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Database connection successful!',
                'data' => [
                    'connected_database' => $row->db_name ?? 'unknown',
                    'database_user' => $row->db_user ?? 'unknown',
                    'mysql_version' => $row->db_version ?? 'unknown',
                    'table_count' => count($tables),
                    'tables' => $tables,
                    'config' => [
                        'hostname' => $default['hostname'] ?? 'not set',
                        'database' => $default['database'] ?? 'not set',
                        'username' => $default['username'] ?? 'not set',
                        'password' => !empty($default['password']) ? '***hidden***' : '(empty)',
                        'port' => $default['port'] ?? 'not set',
                        'DBDriver' => $default['DBDriver'] ?? 'not set',
                    ],
                    'environment' => [
                        'CI_ENVIRONMENT' => ENVIRONMENT,
                        'env_file_exists' => file_exists(ROOTPATH . '.env'),
                        'env_file_path' => ROOTPATH . '.env',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Database connection failed!',
                'error' => $e->getMessage(),
                'config' => [
                    'hostname' => config('Database')->default['hostname'] ?? 'not set',
                    'database' => config('Database')->default['database'] ?? 'not set',
                    'username' => config('Database')->default['username'] ?? 'not set',
                    'password' => !empty(config('Database')->default['password']) ? '***hidden***' : '(empty)',
                ],
                'environment' => [
                    'CI_ENVIRONMENT' => ENVIRONMENT,
                    'env_file_exists' => file_exists(ROOTPATH . '.env'),
                ]
            ], 500);
        }
    }
}
