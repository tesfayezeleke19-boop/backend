<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class DatabaseTest extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $db = Database::connect();
            $db->initialize();

            $query = $db->query(
                'SELECT current_database() AS database_name,
                        CURRENT_TIMESTAMP AS server_time'
            );

            $result = $query->getRowArray();

            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'success' => true,
                    'message' => 'PostgreSQL connection successful.',
                    'database' => $result['database_name'],
                    'server_time' => $result['server_time'],
                ]);
        } catch (\Throwable $e) {
            log_message(
                'error',
                'Database connection test failed: ' . $e->getMessage()
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Database connection failed.',
                    'error' => ENVIRONMENT === 'development'
                        ? $e->getMessage()
                        : 'Check the backend configuration and server logs.',
                ]);
        }
    }
}