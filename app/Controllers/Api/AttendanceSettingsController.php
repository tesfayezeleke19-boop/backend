<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class AttendanceSettingsController extends ResourceController
{
    protected $format = 'json';

    private $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /*
     * GET /api/attendance/settings
     */

    public function show()
    {
        $settings = $this->db->table('attendance_settings')
            ->where('id', 1)
            ->get()
            ->getRowArray();

        if (!$settings) {
            return $this->failNotFound('Attendance settings have not been configured.');
        }

        return $this->respond([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /*
     * PUT /api/attendance/settings
     *
     * JSON:
     * {
     *   "required_work_minutes": 510,
     *   "overtime_enabled": true
     * }
     */

    public function update()
    {
        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            return $this->fail('Invalid JSON request.', 400);
        }

        $minutes = filter_var(
            $payload['required_work_minutes'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($minutes === false || $minutes < 1 || $minutes > 1440) {
            return $this->fail(
                'Required work duration must be between 1 and 1440 minutes.',
                400
            );
        }

        if (!array_key_exists('overtime_enabled', $payload) ||
            !is_bool($payload['overtime_enabled'])) {
            return $this->fail(
                'overtime_enabled must be true or false.',
                400
            );
        }

        $userId = $this->authenticatedUserId();

        $data = [
            'required_work_minutes' => $minutes,
            'overtime_enabled' => $payload['overtime_enabled'],
            'updated_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = $this->db->table('attendance_settings')
            ->where('id', 1)
            ->countAllResults() > 0;

        if ($exists) {
            $this->db->table('attendance_settings')
                ->where('id', 1)
                ->update($data);
        } else {
            $data['id'] = 1;
            $this->db->table('attendance_settings')->insert($data);
        }

        if ($this->db->transStatus() === false) {
            return $this->failServerError('Could not update attendance settings.');
        }

        $settings = $this->db->table('attendance_settings')
            ->where('id', 1)
            ->get()
            ->getRowArray();

        return $this->respond([
            'success' => true,
            'message' => 'Attendance settings updated successfully.',
            'data' => $settings,
        ]);
    }

    private function authenticatedUserId(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');

        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return null;
        }

        $hash = hash('sha256', trim($matches[1]));

        $session = $this->db->table('user_sessions')
            ->select('user_id')
            ->where('token_hash', $hash)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        return $session['user_id'] ?? null;
    }
}
