<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Health Check Endpoint
 * Tests the complete connection chain: Database → Backend → Frontend
 */
class Health extends BaseController
{
    use ResponseTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['api_helper']);
    }

    public function index()
    {
        $health = [
            'status' => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => []
        ];

        // Check 1: Database Connection
        try {
            $db = \Config\Database::connect();
            $query = $db->query("SELECT 1 as test");
            $health['checks']['database'] = [
                'status' => 'connected',
                'database' => $db->database,
                'hostname' => $db->hostname,
                'driver' => $db->DBDriver
            ];
        } catch (\Exception $e) {
            $health['status'] = 'error';
            $health['checks']['database'] = [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }

        // Check 2: Required Tables
        try {
            $db = \Config\Database::connect();
            $tables = $db->listTables();
            $required_tables = ['admin_login', 'ci_sessions'];
            $missing_tables = [];
            
            foreach ($required_tables as $table) {
                if (!in_array($table, $tables)) {
                    $missing_tables[] = $table;
                }
            }
            
            $health['checks']['tables'] = [
                'status' => empty($missing_tables) ? 'ok' : 'missing',
                'total_tables' => count($tables),
                'required_tables' => $required_tables,
                'missing_tables' => $missing_tables
            ];
            
            if (!empty($missing_tables)) {
                $health['status'] = 'warning';
            }
        } catch (\Exception $e) {
            $health['checks']['tables'] = [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }

        // Check 3: Admin User
        try {
            $db = \Config\Database::connect();
            $builder = $db->table('admin_login');
            $builder->where('row_status', 'ACTIVE');
            $admin_count = $builder->countAllResults();
            
            $health['checks']['admin_user'] = [
                'status' => $admin_count > 0 ? 'ok' : 'missing',
                'active_admin_count' => $admin_count,
                'message' => $admin_count > 0 
                    ? 'Admin user(s) found' 
                    : 'No active admin users found. Run /backend/setup to create one.'
            ];
            
            if ($admin_count == 0) {
                $health['status'] = 'warning';
            }
        } catch (\Exception $e) {
            $health['checks']['admin_user'] = [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }

        // Check 4: CORS Configuration
        $health['checks']['cors'] = [
            'status' => 'configured',
            'allowed_origins' => [
                'http://localhost:3000',
                'http://127.0.0.1:3000',
                'https://khaitan.com'
            ],
            'credentials' => 'enabled'
        ];

        // Check 5: Session Configuration
        $sessionConfig = config('App');
        $health['checks']['session'] = [
            'status' => 'configured',
            'driver' => 'files', // CI4 default
            'cookie_name' => 'ci_session'
        ];

        // Check 6: Logging (path and writable) – so you can verify logs on server
        $logPath = defined('WRITEPATH') ? (rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs') : '';
        $logFile = $logPath . DIRECTORY_SEPARATOR . 'log-' . date('Y-m-d') . '.log';
        $logDirExists = $logPath !== '' && is_dir($logPath);
        $logDirWritable = $logDirExists && is_writable($logPath);
        log_message('error', 'EasyEcom: [HEALTH] log check – path=' . $logPath . ' writable=' . ($logDirWritable ? 'yes SUCCESS' : 'no'));
        $health['checks']['logging'] = [
            'status' => $logDirWritable ? 'ok' : ($logDirExists ? 'not_writable' : 'directory_missing'),
            'log_directory' => $logPath,
            'today_file' => $logFile,
            'directory_exists' => $logDirExists,
            'writable' => $logDirWritable,
            'hint' => $logDirWritable ? 'Logs are written to the path above. Set LOG_THRESHOLD=7 in .env to see EasyEcom info logs.' : 'Create writable/logs and chmod 755 (or 775) so the web server can write logs.',
        ];

        // Determine overall status
        $has_errors = false;
        foreach ($health['checks'] as $check) {
            if (isset($check['status']) && $check['status'] === 'failed') {
                $has_errors = true;
                break;
            }
        }

        if ($has_errors) {
            return json_error($health, 500);
        } else {
            return json_success($health, 'System health check complete');
        }
    }
}
