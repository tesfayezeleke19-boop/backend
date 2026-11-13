<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Throwable;

class AttendanceController extends ResourceController
{
    protected $format = 'json';

    private $db;

    private const STATUSES = ['PRESENT', 'ABSENT'];

    private const OVERTIME_GRACE_MINUTES = 15;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /*
     * GET /api/attendance
     */
    public function index()
    {
        $builder = $this->attendanceQuery();

        $date = $this->request->getGet('date');
        $month = $this->request->getGet('month');
        $employeeId = $this->request->getGet('employee_id');
        $status = strtoupper(trim((string) $this->request->getGet('status')));

        if ($date && $this->validDate($date)) {
            $builder->where('a.attendance_date', $date);
        }

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $builder->where('a.attendance_date >=', $month . '-01');
            $builder->where(
                'a.attendance_date <=',
                date('Y-m-t', strtotime($month . '-01'))
            );
        }

        if ($employeeId) {
            $employeeUuid = $this->findEmployeeUuid($employeeId);

            if (!$employeeUuid) {
                return $this->failNotFound('Employee not found.');
            }

            $builder->where('a.employee_id', $employeeUuid);
        }

        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                return $this->fail('Invalid attendance status.', 400);
            }

            $builder->where('a.status', $status);
        }

        $records = $builder
            ->orderBy('a.attendance_date', 'DESC')
            ->orderBy('e.full_name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'status' => 'success',
            'message' => 'Attendance records retrieved successfully.',
            'data' => $records,
        ]);
    }

    /*
     * GET /api/attendance/day/{date}
     */
    public function day($date = null)
    {
        if (!$this->validDate((string) $date)) {
            return $this->fail('Invalid Gregorian date.', 400);
        }

        // Return every active employee for the selected date.
        // Employees without saved records are represented as ABSENT.
        $records = $this->db->table('employees e')
            ->select("
                a.id,
                a.attendance_id,
                e.id AS employee_id,
                e.employee_id AS erp_employee_id,
                e.machine_employee_no,
                e.full_name,
                e.position,
                '{$date}' AS attendance_date,
                CASE
                    WHEN a.status = 'PRESENT' THEN 'PRESENT'
                    ELSE 'ABSENT'
                END AS status,
                a.clock_in,
                a.clock_out,
                COALESCE(a.work_minutes, 0) AS work_minutes,
                COALESCE(a.overtime_minutes, 0) AS overtime_minutes,
                COALESCE(a.overtime_hours, 0) AS overtime_hours,
                a.note,
                a.source,
                a.import_id
            ", false)
            ->join(
                'attendance_records a',
                'a.employee_id = e.id AND a.attendance_date = ' .
                $this->db->escape($date),
                'left'
            )
            ->where('e.is_active', true)
            ->orderBy('e.full_name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'status' => 'success',
            'date' => $date,
            'data' => $records,
        ]);
    }

    /*
     * GET /api/attendance/monthly?month=2026-11
     */
    public function monthly()
    {
        $month = trim((string) $this->request->getGet('month'));

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->fail('Month must use YYYY-MM format.', 400);
        }

        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $records = $this->attendanceQuery()
            ->where('a.attendance_date >=', $start)
            ->where('a.attendance_date <=', $end)
            ->orderBy('a.attendance_date', 'ASC')
            ->orderBy('e.full_name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'status' => 'success',
            'month' => $month,
            'start_date' => $start,
            'end_date' => $end,
            'data' => $records,
        ]);
    }

    /*
     * GET /api/attendance/{id}
     */
    public function show($id = null)
    {
        $builder = $this->attendanceQuery();

        if ($this->isUuid($id)) {
            $builder->where('a.id', $id);
        } else {
            $builder->where('a.attendance_id', $id);
        }

        $record = $builder->get()->getRowArray();

        if (!$record) {
            return $this->failNotFound('Attendance record not found.');
        }

        return $this->respond([
            'status' => 'success',
            'data' => $record,
        ]);
    }

    /*
     * POST /api/attendance
     *
     * Creates a new attendance record or overwrites the existing
     * record for the same employee and attendance date.
     *
     * Overtime resolution priority:
     *   1. Explicit overtime_hours from the client (manual HR override).
     *   2. Explicit overtime_minutes from the client.
     *   3. Auto-calculate from work_minutes vs standard working hours.
     */
    public function create()
    {
        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            return $this->fail('Invalid JSON request.', 400);
        }

        $employeeId = $this->findEmployeeUuid(
            $payload['employee_id'] ?? ''
        );

        if (!$employeeId) {
            return $this->fail('Employee not found.', 404);
        }

        $date = trim((string) ($payload['attendance_date'] ?? ''));

        if (!$this->validDate($date)) {
            return $this->fail(
                'A valid attendance date is required.',
                400
            );
        }

        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        if (!in_array($status, self::STATUSES, true)) {
            return $this->fail('Invalid attendance status.', 400);
        }

        try {
            $clockIn = $this->normalizeTime(
                $payload['clock_in'] ?? null
            );

            $clockOut = $this->normalizeTime(
                $payload['clock_out'] ?? null
            );
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), 400);
        }

        $workMinutes = max(
            0,
            (int) ($payload['work_minutes'] ?? 0)
        );

        if ($clockIn && $clockOut) {
            $workMinutes = $this->calculateDuration(
                $clockIn,
                $clockOut
            );
        } else {
            $workMinutes = 0;
        }

        // Resolve overtime: manual override wins, otherwise auto-calculate.
        $overtimeMinutes = $this->resolveOvertimeMinutes(
            $payload,
            $workMinutes
        );

        $userId = $this->authenticatedUserId();
        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            // Lock and check the existing employee/date record.
            $query = $this->db->query(
                'SELECT *
                 FROM attendance_records
                 WHERE employee_id = ?
                 AND attendance_date = ?
                 FOR UPDATE',
                [$employeeId, $date]
            );

            if ($query === false) {
                throw new \RuntimeException(
                    'Could not check existing attendance record.'
                );
            }

            $existing = $query->getRowArray();

            $record = [
                'employee_id' => $employeeId,
                'attendance_date' => $date,
                'status' => $status,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'work_minutes' => $workMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'overtime_hours' => round($overtimeMinutes / 60, 2),
                'source' => 'MANUAL',
                'import_id' => null,
                'note' => trim((string) ($payload['note'] ?? '')),
                'correction_reason' => trim(
                    (string) ($payload['correction_reason'] ?? '')
                ),
                'updated_by' => $userId,
                'updated_at' => $now,
            ];

            if ($existing) {
                // Overwrite the existing record without changing its ID.
                $this->db->table('attendance_records')
                    ->where('id', $existing['id'])
                    ->update($record);

                $recordId = $existing['id'];

                $action = 'MANUAL_ATTENDANCE_OVERWRITTEN';

                $before = $existing;
            } else {
                // Create a new record.
                $recordId = $this->uuid();

                $record['id'] = $recordId;
                $record['created_by'] = $userId;
                $record['created_at'] = $now;

                $this->db->table('attendance_records')
                    ->insert($record);

                $action = 'MANUAL_ATTENDANCE_CREATED';

                $before = null;
            }

            if ($this->db->transStatus() === false) {
                $dbError = $this->db->error();

                throw new \RuntimeException(
                    'Attendance database write failed: ' .
                    ($dbError['message'] ?? 'Unknown database error')
                );
            }

            $savedRecord = $this->db->table('attendance_records')
                ->where('id', $recordId)
                ->get()
                ->getRowArray();

            if (!$savedRecord || empty($savedRecord['attendance_id'])) {
                throw new \RuntimeException(
                    'Could not retrieve the saved attendance record.'
                );
            }

            $this->audit(
                $recordId,
                $employeeId,
                $date,
                $action,
                $before,
                $savedRecord,
                $record['correction_reason'],
                $userId
            );

            if ($this->db->transStatus() === false) {
                $dbError = $this->db->error();

                throw new \RuntimeException(
                    'Attendance audit failed: ' .
                    ($dbError['message'] ?? 'Unknown database error')
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'status' => 'success',
                'message' => $existing
                    ? 'Existing attendance record overwritten successfully.'
                    : 'Attendance record created successfully.',
                'data' => $savedRecord,
            ]);

        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance create/overwrite: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Could not save attendance record: ' . $e->getMessage()
            );
        }
    }

    /*
     * PUT /api/attendance/{id}
     *
     * Overtime resolution priority:
     *   1. Explicit overtime_hours from the client (manual HR override).
     *   2. Explicit overtime_minutes from the client.
     *   3. Auto-calculate from work_minutes vs standard working hours.
     */
    public function update($id = null)
    {
        $existing = $this->findAttendance($id);

        if (!$existing) {
            return $this->failNotFound('Attendance record not found.');
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            return $this->fail('Invalid JSON request.', 400);
        }

        $status = strtoupper(trim((string) (
            $payload['status'] ?? $existing['status']
        )));

        if (!in_array($status, self::STATUSES, true)) {
            return $this->fail('Invalid attendance status.', 400);
        }

        $reason = trim((string) (
            $payload['correction_reason'] ?? ''
        ));

        if ($reason === '') {
            return $this->fail(
                'A correction reason is required for manual attendance changes.',
                400
            );
        }

        try {
            $clockIn = array_key_exists('clock_in', $payload)
                ? $this->normalizeTime($payload['clock_in'])
                : $existing['clock_in'];

            $clockOut = array_key_exists('clock_out', $payload)
                ? $this->normalizeTime($payload['clock_out'])
                : $existing['clock_out'];

        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), 400);
        }

        $workMinutes = array_key_exists('work_minutes', $payload)
            ? max(0, (int) $payload['work_minutes'])
            : (int) ($existing['work_minutes'] ?? 0);

        // If punches were changed but work_minutes was not explicitly
        // provided, recalculate work duration from the punches.
        if (
            !array_key_exists('work_minutes', $payload) &&
            (
                array_key_exists('clock_in', $payload) ||
                array_key_exists('clock_out', $payload)
            )
        ) {
            $workMinutes = ($clockIn && $clockOut)
                ? $this->calculateDuration($clockIn, $clockOut)
                : 0;
        }

        // Resolve overtime: manual override wins, otherwise auto-calculate.
        $overtimeMinutes = $this->resolveOvertimeMinutes(
            $payload,
            $workMinutes
        );

        $userId = $this->authenticatedUserId();

        $update = [
            'status' => $status,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'work_minutes' => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_hours' => round($overtimeMinutes / 60, 2),
            'note' => trim((string) (
                $payload['note'] ?? $existing['note']
            )),
            'correction_reason' => $reason,
            'updated_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->transBegin();

        try {
            $this->db->table('attendance_records')
                ->where('id', $existing['id'])
                ->update($update);

            $this->audit(
                $existing['id'],
                $existing['employee_id'],
                $existing['attendance_date'],
                'MANUAL_ATTENDANCE_CORRECTED',
                $existing,
                $update,
                $reason,
                $userId
            );

            if ($this->db->transStatus() === false) {
                $dbError = $this->db->error();

                throw new \RuntimeException(
                    'Could not update attendance record: ' .
                    ($dbError['message'] ?? 'Unknown database error')
                );
            }

            $this->db->transCommit();

            $updatedRecord = $this->findAttendance($existing['id']);

            return $this->respond([
                'status' => 'success',
                'message' => 'Attendance record updated successfully.',
                'data' => $updatedRecord,
            ]);

        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance update: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Could not update attendance record: ' . $e->getMessage()
            );
        }
    }

    /*
     * DELETE /api/attendance/{id}
     */
    public function delete($id = null)
    {
        $existing = $this->findAttendance($id);

        if (!$existing) {
            return $this->failNotFound('Attendance record not found.');
        }

        $payload = $this->request->getJSON(true);

        $reason = trim((string) (
            is_array($payload) ? ($payload['reason'] ?? '') : ''
        ));

        if ($reason === '') {
            return $this->fail(
                'A reason is required to delete an attendance record.',
                400
            );
        }

        $userId = $this->authenticatedUserId();

        $this->db->transBegin();

        try {
            $this->audit(
                $existing['id'],
                $existing['employee_id'],
                $existing['attendance_date'],
                'ATTENDANCE_DELETED',
                $existing,
                null,
                $reason,
                $userId
            );

            $this->db->table('attendance_records')
                ->where('id', $existing['id'])
                ->delete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Could not delete attendance record.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'status' => 'success',
                'message' => 'Attendance record deleted successfully.',
            ]);

        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance delete: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Could not delete attendance record.'
            );
        }
    }

    /*
     * COMMON ATTENDANCE QUERY
     */
    private function attendanceQuery()
    {
        return $this->db->table('attendance_records a')
            ->select(
                'a.*, e.employee_id AS erp_employee_id, e.full_name, e.position'
            )
            ->join(
                'employees e',
                'e.id = a.employee_id',
                'left'
            );
    }

    /*
     * FIND ATTENDANCE RECORD
     */
    private function findAttendance($id): ?array
    {
        $builder = $this->db->table('attendance_records');

        if ($this->isUuid($id)) {
            $builder->where('id', $id);
        } else {
            $builder->where('attendance_id', $id);
        }

        return $builder->get()->getRowArray() ?: null;
    }

    /*
     * FIND EMPLOYEE UUID
     */
    private function findEmployeeUuid($value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $builder = $this->db->table('employees')
            ->select('id');

        if ($this->isUuid($value)) {
            $builder->where('id', $value);
        } else {
            $builder->where('employee_id', $value);
        }

        $row = $builder->get()->getRowArray();

        return $row['id'] ?? null;
    }

    /*
     * RESOLVE OVERTIME MINUTES
     *
     * Priority:
     *   1. Explicit overtime_hours from the client (manual HR override).
     *   2. Explicit overtime_minutes from the client.
     *   3. Auto-calculate from work_minutes vs standard working hours.
     *
     * When neither explicit field is present, the value auto-computes
     * from work_minutes so clock-in/clock-out and import flows keep
     * working exactly as before.
     */
    private function resolveOvertimeMinutes(
        array $payload,
        int $workMinutes
    ): int {
        $hasHours =
            array_key_exists('overtime_hours', $payload) &&
            $payload['overtime_hours'] !== null &&
            $payload['overtime_hours'] !== '';

        $hasMinutes =
            array_key_exists('overtime_minutes', $payload) &&
            $payload['overtime_minutes'] !== null &&
            $payload['overtime_minutes'] !== '';

        if ($hasHours) {
            $hours = (float) $payload['overtime_hours'];

            if (!is_finite($hours) || $hours < 0) {
                $hours = 0.0;
            }

            return (int) round($hours * 60);
        }

        if ($hasMinutes) {
            $minutes = (int) $payload['overtime_minutes'];

            return $minutes > 0 ? $minutes : 0;
        }

        $standardMinutes = $this->standardWorkingMinutes();

        return $this->calculateOvertimeMinutes(
            $workMinutes,
            $standardMinutes
        );
    }

    /*
     * GET STANDARD WORKING MINUTES
     *
     * operational_settings columns:
     * setting_key
     * setting_value
     *
     * The setting value is stored in HOURS.
     */
    private function standardWorkingMinutes(): int
    {
        try {
            $query = $this->db->table('operational_settings')
                ->select('setting_value')
                ->where(
                    'setting_key',
                    'standard_working_hours'
                )
                ->limit(1)
                ->get();

            if ($query === false) {
                log_message(
                    'warning',
                    'Could not read standard_working_hours: ' .
                    json_encode($this->db->error())
                );

                return 480;
            }

            $row = $query->getRowArray();

            $hours = isset($row['setting_value'])
                ? (float) $row['setting_value']
                : 8.0;

            if ($hours <= 0 || $hours > 24) {
                $hours = 8.0;
            }

            return (int) round($hours * 60);

        } catch (Throwable $e) {
            log_message(
                'warning',
                'Could not read standard_working_hours: ' .
                $e->getMessage()
            );

            return 480;
        }
    }

    /*
     * CALCULATE OVERTIME (auto, from work minutes)
     *
     * Example with 8 standard hours:
     *
     * 8h 10m = 0 overtime
     * 8h 15m = 0 overtime
     * 8h 16m = 16 overtime minutes
     * 9h     = 60 overtime minutes
     * 10h    = 120 overtime minutes
     */
    private function calculateOvertimeMinutes(
        int $workMinutes,
        int $standardMinutes
    ): int {
        if (
            $workMinutes <=
            ($standardMinutes + self::OVERTIME_GRACE_MINUTES)
        ) {
            return 0;
        }

        return max(0, $workMinutes - $standardMinutes);
    }

    /*
     * VALIDATE GREGORIAN DATE
     */
    private function validDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $date);

        return $d && $d->format('Y-m-d') === $date;
    }

    /*
     * NORMALIZE TIME
     */
    private function normalizeTime($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (!preg_match(
            '/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
            $value
        )) {
            throw new \InvalidArgumentException(
                'Invalid time format.'
            );
        }

        return strlen($value) === 5
            ? $value . ':00'
            : $value;
    }

    /*
     * CALCULATE WORK DURATION
     */
    private function calculateDuration(
        string $in,
        string $out
    ): int {
        $start = strtotime($in);
        $end = strtotime($out);

        if ($start === false || $end === false) {
            return 0;
        }

        // Support shifts that continue after midnight.
        if ($end < $start) {
            $end += 86400;
        }

        return (int) floor(($end - $start) / 60);
    }

    /*
     * CHECK UUID
     */
    private function isUuid($value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) === 1;
    }

    /*
     * GET AUTHENTICATED USER ID
     */
    private function authenticatedUserId(): ?string
    {
        $header = $this->request->getHeaderLine(
            'Authorization'
        );

        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return null;
        }

        $hash = hash(
            'sha256',
            trim($matches[1])
        );

        $session = $this->db->table('user_sessions')
            ->select('user_id')
            ->where('token_hash', $hash)
            ->where('revoked_at', null)
            ->where(
                'expires_at >',
                date('Y-m-d H:i:s')
            )
            ->get()
            ->getRowArray();

        return $session['user_id'] ?? null;
    }

    /*
     * WRITE AUDIT LOG
     */
    private function audit(
        ?string $recordId,
        ?string $employeeId,
        ?string $date,
        string $action,
        $before,
        $after,
        string $reason,
        ?string $userId
    ): void {
        $this->db->table('attendance_audit_logs')->insert([
            'id' => $this->uuid(),
            'attendance_record_id' => $recordId,
            'employee_id' => $employeeId,
            'attendance_date' => $date,
            'action' => $action,
            'before_data' => $before === null
                ? null
                : json_encode($before),
            'after_data' => $after === null
                ? null
                : json_encode($after),
            'reason' => $reason,
            'performed_by' => $userId,
            'performed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /*
     * GENERATE UUID
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f) | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f) | 0x80
        );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}