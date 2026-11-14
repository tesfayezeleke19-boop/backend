<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class DatabaseTest extends Controller
{
    public function index()
    {
        try {
            if (!extension_loaded('pgsql')) {
                throw new \RuntimeException(
                    'PHP PostgreSQL extension is not installed.'
                );
            }

            $db = Database::connect();

            $result = $db->query(
                'SELECT current_database() AS database_name,
                        current_user AS database_user,
                        NOW() AS server_time'
            )->getRowArray();

            return $this->response->setJSON([
                'success' => true,
                'message' => 'PostgreSQL connection successful!',
                'pgsql_extension' => true,
                'database' => $result['database_name'],
                'user' => $result['database_user'],
                'server_time' => $result['server_time'],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Database test failed: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Database connection failed.',
            ]);
        }
    }
}