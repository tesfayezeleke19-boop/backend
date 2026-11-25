
<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class DatabaseTest extends Controller
{
    public function index()
    {
        try {
            // 1. Check PostgreSQL PHP extension
            if (!extension_loaded('pgsql')) {
                throw new \RuntimeException(
                    'PHP PostgreSQL extension is not installed.'
                );
            }

            // 2. Connect to the configured database
            $db = Database::connect();

            // 3. Execute a test query
            $result = $db->query(
                'SELECT
                    current_database() AS database_name,
                    current_user AS database_user,
                    NOW() AS server_time'
            )->getRowArray();

            // 4. Return successful connection response
            return $this->response->setStatusCode(200)->setJSON([
                'success' => true,
                'message' => 'PostgreSQL connection successful!',
                'pgsql_extension' => true,
                'database' => $result['database_name'],
                'user' => $result['database_user'],
                'server_time' => $result['server_time'],
            ]);

        } catch (\Throwable $e) {

            // 5. Log the actual error
            log_message(
                'error',
                'Database test failed: {message}',
                ['message' => $e->getMessage()]
            );

            // 6. Return diagnostic information temporarily
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Database connection failed.',
                    'error_type' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
        }
    }
}
