<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Throwable;

class AttendanceImportController extends ResourceController
{
    protected $format = 'json';

    private $db;

    private const STATUSES = [
        'PRESENT',
        'ABSENT',
        'LATE',
        'LEAVE',
    ];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/attendance/import/preview
    |--------------------------------------------------------------------------
    */

    public function preview()
    {
        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            return $this->fail('A valid JSON request is required.', 400);
        }

        $type = strtoupper(trim((string) (
            $payload['import_type'] ?? ''
        )));

        if (!in_array($type, ['DAILY', 'MONTHLY'], true)) {
            return $this->fail(
                'Import type must be DAILY or MONTHLY.',
                400
            );
        }

        $inputRows = $payload['rows'] ?? [];

        if (!is_array($inputRows) || empty($inputRows)) {
            return $this->fail(
                'No attendance rows were submitted.',
                400
            );
        }

        if (count($inputRows) > 20000) {
            return $this->fail(
                'The import contains too many rows.',
                400
            );
        }

        $startDate = trim((string) (
            $payload['start_date'] ??
            $payload['date'] ??
            ''
        ));

        $endDate = trim((string) (
            $payload['end_date'] ??
            $payload['date'] ??
            ''
        ));

        if ($startDate !== '' && !$this->validDate($startDate)) {
            return $this->fail('Invalid start date.', 400);
        }

        if ($endDate !== '' && !$this->validDate($endDate)) {
            return $this->fail('Invalid end date.', 400);
        }

        if (
            $startDate !== '' &&
            $endDate !== '' &&
            $startDate > $endDate
        ) {
            return $this->fail(
                'Start date cannot be after end date.',
                400
            );
        }

        $settings = $this->db->table('attendance_settings')
            ->where('id', 1)
            ->get()
            ->getRowArray();

        $requiredMinutes = $this->standardWorkingMinutes($settings);

        $employees = $this->db->table('employees')
            ->where('is_active', true)
            ->orderBy('full_name', 'ASC')
            ->get()
            ->getResultArray();

        /*
         * Build employee machine-number lookup.
         */

        $employeeMap = [];

        foreach ($employees as $employee) {
            $machineNo = trim((string) (
                $employee['machine_employee_no'] ?? ''
            ));

            if ($machineNo !== '') {
                $employeeMap[$machineNo] = $employee;
            }
        }

        $normalized = [];
        $dates = [];
        $seen = [];

        $unmatchedSourceCount = 0;

        /*
         * Normalize uploaded rows.
         */

        foreach ($inputRows as $index => $input) {
            $rowNumber = $index + 1;

            if (!is_array($input)) {
                return $this->fail(
                    'Invalid attendance row at position ' . $rowNumber,
                    400
                );
            }

            $machineNo = trim((string) (
                $input['machine_employee_no'] ?? ''
            ));

            $date = trim((string) (
                $input['attendance_date'] ?? ''
            ));

            if ($machineNo === '') {
                return $this->fail(
                    'Machine employee number is missing at row ' . $rowNumber,
                    400
                );
            }

            if (!$this->validDate($date)) {
                return $this->fail(
                    'Invalid Gregorian attendance date at row ' . $rowNumber,
                    400
                );
            }

            if ($startDate !== '' && $date < $startDate) {
                return $this->fail(
                    'Attendance row date is earlier than the specified start date.',
                    400
                );
            }

            if ($endDate !== '' && $date > $endDate) {
                return $this->fail(
                    'Attendance row date is later than the specified end date.',
                    400
                );
            }

            if ($type === 'DAILY') {
                $dailyDate = $startDate ?: $date;

                if ($date !== $dailyDate) {
                    return $this->fail(
                        'A daily import cannot contain multiple attendance dates.',
                        400
                    );
                }
            }

            $employee = $employeeMap[$machineNo] ?? null;

            $key = ($employee['id'] ?? $machineNo) . '|' . $date;

            if (isset($seen[$key])) {
                return $this->fail(
                    'Duplicate attendance row for employee ' .
                    $machineNo . ' on ' . $date,
                    400
                );
            }

            $seen[$key] = true;
            $dates[$date] = true;

            try {
                $clockIn = $this->normalizeTime(
                    $input['clock_in'] ?? null
                );

                $clockOut = $this->normalizeTime(
                    $input['clock_out'] ?? null
                );
            } catch (Throwable $e) {
                return $this->fail(
                    'Invalid clock time at row ' . $rowNumber . ': ' .
                    $e->getMessage(),
                    400
                );
            }

            $workMinutes = max(
                0,
                (int) ($input['work_minutes'] ?? 0)
            );

            $warnings = [];

            if (!$employee) {
                $unmatchedSourceCount++;

                $warnings[] =
                    'Machine employee number does not match an active ERP employee.';
            }

            if (($clockIn === null) xor ($clockOut === null)) {
                $warnings[] = 'Only one attendance punch was provided.';
            }

            if ($clockIn !== null && $clockOut !== null) {
                $workMinutes = $this->calculateDuration(
                    $clockIn,
                    $clockOut
                );

                // Overtime = worked time − standard working hours,
                // but only if both punches exist and the excess is
                // strictly greater than the grace period.
                $overtimeMinutes = $this->computeOvertime(
                    $workMinutes,
                    $requiredMinutes
                );
            } else {
                // Overtime is always zero unless both punches are available.
                $overtimeMinutes = 0;
                $workMinutes = 0;
            }

            if (
                !$clockIn &&
                !$clockOut &&
                $workMinutes > 0
            ) {
                $warnings[] =
                    'Work duration was provided without clock-in and clock-out punches.';
            }

            $status = $this->classify(
                $workMinutes,
                $requiredMinutes
            );

            /*
             * Preserve existing Leave records and approved leave.
             */

            if ($employee) {
                if (
                    $this->hasApprovedLeave($employee['id'], $date) ||
                    $this->hasExistingLeave($employee['id'], $date)
                ) {
                    $status = 'LEAVE';
                }
            }

            $normalized[] = [
                'machine_employee_no' => $machineNo,
                'employee_id' => $employee['id'] ?? null,
                'attendance_date' => $date,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'work_minutes' => $workMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'proposed_status' => $status,
                'final_status' => $status,
                'raw_data' => $input,
                'warnings' => $warnings,
                'correction_reason' => '',
            ];
        }

        if (empty($dates)) {
            return $this->fail(
                'No valid attendance dates were provided.',
                400
            );
        }

        $sortedDates = array_keys($dates);
        sort($sortedDates);

        /*
         * Generate missing employee attendance rows.
         *
         * Employees missing from the machine file become ABSENT,
         * except when they have approved or existing Leave.
         */

        $seenEmployeeDates = [];

        foreach ($normalized as $row) {
            if ($row['employee_id'] !== null) {
                $seenEmployeeDates[
                    $row['employee_id'] . '|' . $row['attendance_date']
                ] = true;
            }
        }

        foreach ($sortedDates as $date) {
            foreach ($employees as $employee) {
                $key = $employee['id'] . '|' . $date;

                if (isset($seenEmployeeDates[$key])) {
                    continue;
                }

                $status = 'ABSENT';

                if (
                    $this->hasApprovedLeave($employee['id'], $date) ||
                    $this->hasExistingLeave($employee['id'], $date)
                ) {
                    $status = 'LEAVE';
                }

                $normalized[] = [
                    'machine_employee_no' =>
                        $employee['machine_employee_no'] ?? null,

                    'employee_id' => $employee['id'],
                    'attendance_date' => $date,
                    'clock_in' => null,
                    'clock_out' => null,
                    'work_minutes' => 0,
                    'overtime_minutes' => 0,
                    'proposed_status' => $status,
                    'final_status' => $status,

                    'raw_data' => [
                        'generated' => true,
                        'employee_id' => $employee['employee_id'],
                        'employee_name' => $employee['full_name'],
                    ],

                    'warnings' => [
                        'Employee was not found in the machine file for this date.',
                    ],

                    'correction_reason' => '',
                ];

                $seenEmployeeDates[$key] = true;
            }
        }

        /*
         * Recalculate summary after generating missing employees.
         */

        $matchedCount = 0;
        $warningCount = 0;

        foreach ($normalized as $row) {
            if ($row['employee_id'] !== null) {
                $matchedCount++;
            }

            if (!empty($row['warnings'])) {
                $warningCount++;
            }
        }

        $unmatchedCount = $unmatchedSourceCount;

        $actualStartDate = $sortedDates[0];
        $actualEndDate = $sortedDates[count($sortedDates) - 1];

        $filename = trim((string) (
            $payload['original_filename'] ??
            $payload['filename'] ??
            'Attendance import'
        ));

        if ($filename === '') {
            $filename = 'Attendance import';
        }

        $filename = substr($filename, 0, 255);

        /*
         * Save preview and rows transactionally.
         */

        $this->db->transBegin();

        try {
            $userId = $this->authenticatedUserId();

            $importId = $this->uuid();
            $now = date('Y-m-d H:i:s');

            $this->db->table('attendance_imports')->insert([
                'id' => $importId,
                'original_filename' => $filename,
                'import_type' => $type,
                'start_date' => $actualStartDate,
                'end_date' => $actualEndDate,
                'status' => 'PREVIEW',
                'total_rows' => count($normalized),
                'matched_rows' => $matchedCount,
                'unmatched_rows' => $unmatchedCount,
                'warning_rows' => $warningCount,
                'imported_by' => $userId,
                'notes' => trim((string) (
                    $payload['notes'] ?? ''
                )),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($normalized as $index => $row) {
                $this->db->table('attendance_import_rows')->insert([
                    'id' => $this->uuid(),
                    'import_id' => $importId,
                    'row_number' => $index + 1,

                    'machine_employee_no' =>
                        $row['machine_employee_no'],

                    'employee_id' => $row['employee_id'],
                    'attendance_date' => $row['attendance_date'],

                    'clock_in' => $row['clock_in'],
                    'clock_out' => $row['clock_out'],

                    'work_minutes' => $row['work_minutes'],
                    'overtime_minutes' => $row['overtime_minutes'],

                    'proposed_status' => $row['proposed_status'],
                    'final_status' => $row['final_status'],

                    'raw_data' => json_encode($row['raw_data']),
                    'warnings' => json_encode($row['warnings']),

                    'correction_reason' => '',

                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->audit(
                null,
                null,
                null,
                'IMPORT_PREVIEW_CREATED',
                null,
                [
                    'import_id' => $importId,
                    'total_rows' => count($normalized),
                    'matched_rows' => $matchedCount,
                    'unmatched_rows' => $unmatchedCount,
                ],
                '',
                $userId
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Could not save attendance import preview.'
                );
            }

            $this->db->transCommit();

            $import = $this->db->table('attendance_imports')
                ->where('id', $importId)
                ->get()
                ->getRowArray();

            if (!$import) {
                throw new \RuntimeException(
                    'Attendance preview was saved but could not be retrieved.'
                );
            }

            $rows = $this->db->table('attendance_import_rows r')
                ->select('r.*, e.full_name')
                ->join(
                    'employees e',
                    'e.id = r.employee_id',
                    'left'
                )
                ->where('r.import_id', $importId)
                ->orderBy('r.attendance_date', 'ASC')
                ->orderBy('r.row_number', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($rows as &$row) {
                $rawData = $this->jsonArray(
                    $row['raw_data'] ?? null
                );

                $row['raw_data'] = $rawData;

                $row['warnings'] = $this->jsonArray(
                    $row['warnings'] ?? null
                );

                $row['work_minutes'] = (int) $row['work_minutes'];
                $row['overtime_minutes'] = (int) $row['overtime_minutes'];

                $row['employee_name'] =
                    $row['full_name'] ??
                    $rawData['employee_name'] ??
                    null;

                unset($row['full_name']);
            }

            unset($row);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Attendance preview created successfully.',
                'import' => $import,
                'rows' => $rows,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance import preview: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Could not create attendance preview: ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/attendance/imports
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $imports = $this->db->table('attendance_imports')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => $imports,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/attendance/import/{id}
    |--------------------------------------------------------------------------
    */

    public function show($id = null)
    {
        $import = $this->db->table('attendance_imports')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$import) {
            return $this->failNotFound(
                'Attendance import not found.'
            );
        }

        $rows = $this->db->table('attendance_import_rows r')
            ->select(
                'r.*, e.employee_id AS erp_employee_id, e.full_name'
            )
            ->join(
                'employees e',
                'e.id = r.employee_id',
                'left'
            )
            ->where('r.import_id', $id)
            ->orderBy('r.attendance_date', 'ASC')
            ->orderBy('r.row_number', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $rawData = $this->jsonArray($row['raw_data'] ?? null);

            $row['raw_data'] = $rawData;

            $row['warnings'] = $this->jsonArray(
                $row['warnings'] ?? null
            );

            $row['work_minutes'] = (int) $row['work_minutes'];
            $row['overtime_minutes'] = (int) $row['overtime_minutes'];

            $row['employee_name'] =
                $row['full_name'] ??
                $rawData['employee_name'] ??
                null;
        }

        unset($row);

        return $this->respond([
            'success' => true,
            'import' => $import,
            'rows' => $rows,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PUT /api/attendance/import/{id}/rows
    |
    | HR can update final_status, clock_in, clock_out, work_minutes and
    | overtime_minutes in the preview. Overtime is fully controlled by HR
    | when they send overtime_minutes; auto-calculation only fills it in
    | when the field is absent.
    |--------------------------------------------------------------------------
    */

    public function updateRows($id = null)
    {
        $import = $this->db->table('attendance_imports')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$import) {
            return $this->failNotFound(
                'Attendance import not found.'
            );
        }

        if ($import['status'] !== 'PREVIEW') {
            return $this->fail(
                'This import is no longer editable.',
                409
            );
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            return $this->fail('Invalid JSON request.', 400);
        }

        $rows = $payload['rows'] ?? [];

        if (!is_array($rows) || empty($rows)) {
            return $this->fail(
                'No attendance rows were provided.',
                400
            );
        }

        $requiredMinutes = $this->standardWorkingMinutes();

        $this->db->transBegin();

        try {
            $userId = $this->authenticatedUserId();

            foreach ($rows as $change) {
                if (!is_array($change)) {
                    throw new \RuntimeException(
                        'Invalid attendance row update.'
                    );
                }

                $rowId = $change['id'] ?? null;

                if (!$rowId) {
                    throw new \RuntimeException(
                        'An attendance import row ID is required.'
                    );
                }

                $existing = $this->db->table('attendance_import_rows')
                    ->where('id', $rowId)
                    ->where('import_id', $id)
                    ->get()
                    ->getRowArray();

                if (!$existing) {
                    throw new \RuntimeException(
                        'An edited preview row was not found.'
                    );
                }

                $status = strtoupper(trim((string) (
                    $change['final_status'] ??
                    $existing['final_status']
                )));

                if (!in_array($status, self::STATUSES, true)) {
                    throw new \RuntimeException(
                        'Invalid attendance status.'
                    );
                }

                $reason = trim((string) (
                    $change['correction_reason'] ??
                    $existing['correction_reason']
                ));

                $update = [
                    'final_status' => $status,
                    'correction_reason' => $reason,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                foreach (['clock_in', 'clock_out'] as $field) {
                    if (array_key_exists($field, $change)) {
                        $update[$field] = $this->normalizeTime(
                            $change[$field]
                        );
                    }
                }

                // work_minutes is only accepted when explicitly sent.
                if (array_key_exists('work_minutes', $change)) {
                    $value = filter_var(
                        $change['work_minutes'],
                        FILTER_VALIDATE_INT
                    );

                    if ($value === false || $value < 0) {
                        throw new \RuntimeException(
                            'Invalid work_minutes value.'
                        );
                    }

                    $update['work_minutes'] = $value;
                }

                // If both punches are present and HR did not send
                // work_minutes, derive it from the punches.
                $effectiveClockIn = array_key_exists('clock_in', $update)
                    ? $update['clock_in']
                    : $existing['clock_in'];
                $effectiveClockOut = array_key_exists('clock_out', $update)
                    ? $update['clock_out']
                    : $existing['clock_out'];

                if (
                    !array_key_exists('work_minutes', $update) &&
                    $effectiveClockIn !== null &&
                    $effectiveClockOut !== null
                ) {
                    $update['work_minutes'] = $this->calculateDuration(
                        $effectiveClockIn,
                        $effectiveClockOut
                    );
                }

                /*
                 * OVERTIME RULES:
                 *
                 *   1. If the client explicitly sends overtime_minutes,
                 *      use it verbatim (HR override).
                 *   2. Otherwise:
                 *        a. If both punches exist → compute from
                 *           work_minutes − standardMinutes with the
                 *           15-minute grace period.
                 *        b. Else → preserve the existing row value.
                 */
                if (array_key_exists('overtime_minutes', $change)) {
                    $value = filter_var(
                        $change['overtime_minutes'],
                        FILTER_VALIDATE_INT
                    );

                    if ($value === false || $value < 0) {
                        throw new \RuntimeException(
                            'Invalid overtime_minutes value.'
                        );
                    }

                    $update['overtime_minutes'] = $value;
                } else {
                    $effectiveWorkMinutes = (int) (
                        $update['work_minutes'] ??
                        $existing['work_minutes'] ??
                        0
                    );

                    if (
                        $effectiveClockIn !== null &&
                        $effectiveClockOut !== null
                    ) {
                        $update['overtime_minutes'] = $this->computeOvertime(
                            $effectiveWorkMinutes,
                            $requiredMinutes
                        );
                    } else {
                        $update['overtime_minutes'] =
                            (int) ($existing['overtime_minutes'] ?? 0);
                    }
                }

                $changed =
                    $status !== $existing['final_status'] ||
                    (
                        array_key_exists('clock_in', $update) &&
                        $update['clock_in'] !== $existing['clock_in']
                    ) ||
                    (
                        array_key_exists('clock_out', $update) &&
                        $update['clock_out'] !== $existing['clock_out']
                    ) ||
                    (
                        array_key_exists('work_minutes', $update) &&
                        (int) $update['work_minutes'] !==
                        (int) $existing['work_minutes']
                    ) ||
                    (
                        array_key_exists('overtime_minutes', $update) &&
                        (int) $update['overtime_minutes'] !==
                        (int) $existing['overtime_minutes']
                    );

                if ($changed && $reason === '') {
                    throw new \RuntimeException(
                        'A correction reason is required for edited rows.'
                    );
                }

                $this->db->table('attendance_import_rows')
                    ->where('id', $rowId)
                    ->update($update);

                if ($changed) {
                    $this->audit(
                        null,
                        $existing['employee_id'],
                        $existing['attendance_date'],
                        'IMPORT_ROW_CORRECTED',
                        $existing,
                        $update,
                        $reason,
                        $userId
                    );
                }
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Could not update preview rows.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Preview rows updated successfully.',
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance import row update: ' . $e->getMessage()
            );

            return $this->fail($e->getMessage(), 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/attendance/import/{id}/approve
    |
    | Uses the HR-edited overtime_minutes stored on each row. No recompute.
    |--------------------------------------------------------------------------
    */

    public function approve($id = null)
    {
        $this->db->transBegin();

        try {
            $import = $this->db->table('attendance_imports')
                ->where('id', $id)
                ->get()
                ->getRowArray();

            if (!$import) {
                $this->db->transRollback();

                return $this->failNotFound(
                    'Attendance import not found.'
                );
            }

            if ($import['status'] !== 'PREVIEW') {
                $this->db->transRollback();

                return $this->fail(
                    'This import has already been processed.',
                    409
                );
            }

            $rows = $this->db->table('attendance_import_rows')
                ->where('import_id', $id)
                ->where('employee_id IS NOT NULL')
                ->orderBy('attendance_date', 'ASC')
                ->orderBy('row_number', 'ASC')
                ->get()
                ->getResultArray();

            if (empty($rows)) {
                $this->db->transRollback();

                return $this->fail(
                    'There are no matched attendance rows to approve.',
                    400
                );
            }

            $userId = $this->authenticatedUserId();
            $now = date('Y-m-d H:i:s');

            $savedRecords = 0;
            $processed = [];

            foreach ($rows as $row) {
                $employeeId = $row['employee_id'];
                $date = $row['attendance_date'];

                $key = $employeeId . '|' . $date;

                if (isset($processed[$key])) {
                    throw new \RuntimeException(
                        'Duplicate employee attendance date detected during approval.'
                    );
                }

                $processed[$key] = true;

                $status = strtoupper(trim((string) (
                    $row['final_status'] ?? ''
                )));

                if (!in_array($status, self::STATUSES, true)) {
                    throw new \RuntimeException(
                        'Invalid final attendance status.'
                    );
                }

                /*
                 * Preserve approved Leave and existing Leave records.
                 */

                $approvedLeave = $this->hasApprovedLeave(
                    $employeeId,
                    $date
                );

                $existing = $this->db->table('attendance_records')
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $date)
                    ->get()
                    ->getRowArray();

                if (
                    $approvedLeave ||
                    ($existing && $existing['status'] === 'LEAVE')
                ) {
                    $status = 'LEAVE';
                }

                $workMinutes = max(
                    0,
                    (int) $row['work_minutes']
                );

                /*
                 * Use HR-edited overtime. Do not recompute from punches
                 * here — the HR value takes precedence.
                 */
                $overtimeMinutes = max(
                    0,
                    (int) $row['overtime_minutes']
                );

                $record = [
                    'employee_id' => $employeeId,
                    'attendance_date' => $date,
                    'status' => $status,

                    'clock_in' => $row['clock_in'],
                    'clock_out' => $row['clock_out'],

                    'work_minutes' => $workMinutes,
                    'overtime_minutes' => $overtimeMinutes,

                    'overtime_hours' => round(
                        $overtimeMinutes / 60,
                        2
                    ),

                    'source' => 'ZKTECO',
                    'import_id' => $id,

                    'note' => 'Imported from attendance file.',

                    'updated_by' => $userId,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    $this->db->table('attendance_records')
                        ->where('id', $existing['id'])
                        ->update($record);

                    $savedRecord = $this->db->table('attendance_records')
                        ->where('id', $existing['id'])
                        ->get()
                        ->getRowArray();

                    $this->audit(
                        $existing['id'],
                        $employeeId,
                        $date,
                        'ATTENDANCE_IMPORT_UPDATED',
                        $existing,
                        $savedRecord,
                        (string) $row['correction_reason'],
                        $userId
                    );
                } else {
                    $record['id'] = $this->uuid();
                    $record['created_by'] = $userId;
                    $record['created_at'] = $now;

                    $this->db->table('attendance_records')
                        ->insert($record);

                    $savedRecord = $this->db->table('attendance_records')
                        ->where('id', $record['id'])
                        ->get()
                        ->getRowArray();

                    if (!$savedRecord) {
                        throw new \RuntimeException(
                            'Could not retrieve an imported attendance record.'
                        );
                    }

                    if (empty($savedRecord['attendance_id'])) {
                        throw new \RuntimeException(
                            'The database did not generate attendance_id.'
                        );
                    }

                    $this->audit(
                        $record['id'],
                        $employeeId,
                        $date,
                        'ATTENDANCE_IMPORT_CREATED',
                        null,
                        $savedRecord,
                        (string) $row['correction_reason'],
                        $userId
                    );
                }

                $savedRecords++;
            }

            $this->db->table('attendance_imports')
                ->where('id', $id)
                ->update([
                    'status' => 'APPROVED',
                    'approved_by' => $userId,
                    'approved_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->audit(
                null,
                null,
                null,
                'IMPORT_APPROVED',
                ['status' => 'PREVIEW'],
                [
                    'status' => 'APPROVED',
                    'import_id' => $id,
                    'saved_records' => $savedRecords,
                ],
                '',
                $userId
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Database error while approving attendance import.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Attendance import approved successfully.',
                'import_id' => $id,
                'saved_records' => $savedRecords,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance import approval: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Attendance approval failed: ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/attendance/import/{id}/cancel
    |--------------------------------------------------------------------------
    */

    public function cancel($id = null)
    {
        $this->db->transBegin();

        try {
            $import = $this->db->table('attendance_imports')
                ->where('id', $id)
                ->get()
                ->getRowArray();

            if (!$import) {
                $this->db->transRollback();

                return $this->failNotFound(
                    'Attendance import not found.'
                );
            }

            if ($import['status'] !== 'PREVIEW') {
                $this->db->transRollback();

                return $this->fail(
                    'Only preview imports can be cancelled.',
                    409
                );
            }

            $userId = $this->authenticatedUserId();
            $now = date('Y-m-d H:i:s');

            $this->db->table('attendance_imports')
                ->where('id', $id)
                ->update([
                    'status' => 'CANCELLED',
                    'updated_at' => $now,
                ]);

            $this->audit(
                null,
                null,
                null,
                'IMPORT_CANCELLED',
                ['status' => 'PREVIEW'],
                [
                    'status' => 'CANCELLED',
                    'import_id' => $id,
                ],
                '',
                $userId
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Could not cancel attendance import.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Attendance import cancelled successfully.',
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Attendance import cancellation: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Could not cancel attendance import.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ATTENDANCE CLASSIFICATION
    |--------------------------------------------------------------------------
    */

    /**
     * Read the working-hours value configured by the administrator.
     * operational_settings.standard_working_hours is stored in hours.
     * Fall back to attendance_settings.required_work_minutes when needed.
     */
    private function standardWorkingMinutes(?array $attendanceSettings = null): int
    {
        try {
            $setting = $this->db->table('operational_settings')
                ->select('setting_value')
                ->where('setting_key', 'standard_working_hours')
                ->get()
                ->getRowArray();

            if (
                $setting &&
                is_numeric($setting['setting_value'] ?? null) &&
                (float) $setting['setting_value'] > 0
            ) {
                return max(
                    1,
                    (int) round((float) $setting['setting_value'] * 60)
                );
            }
        } catch (Throwable $e) {
            // Use attendance_settings fallback if operational setting is unavailable.
        }

        if ($attendanceSettings === null) {
            $attendanceSettings = $this->db->table('attendance_settings')
                ->where('id', 1)
                ->get()
                ->getRowArray() ?? [];
        }

        return max(
            1,
            (int) ($attendanceSettings['required_work_minutes'] ?? 540)
        );
    }

    private function classify(
        int $workMinutes,
        int $requiredMinutes
    ): string {
        if ($workMinutes <= 0) {
            return 'ABSENT';
        }

        if ($workMinutes >= $requiredMinutes) {
            return 'PRESENT';
        }

        return 'LATE';
    }

    /**
     * Compute overtime = (workedMinutes − standardMinutes) if the excess
     * is strictly greater than the 15-minute grace period, otherwise 0.
     */
    private function computeOvertime(
        int $workedMinutes,
        int $standardMinutes
    ): int {
        $excess = $workedMinutes - $standardMinutes;

        if ($excess <= 15) {
            return 0;
        }

        return $excess;
    }

    /*
    |--------------------------------------------------------------------------
    | LEAVE CHECKS
    |--------------------------------------------------------------------------
    */

    private function hasApprovedLeave(
        string $employeeId,
        string $date
    ): bool {
        return $this->db->table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'APPROVED')
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults() > 0;
    }

    private function hasExistingLeave(
        string $employeeId,
        string $date
    ): bool {
        return $this->db->table('attendance_records')
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $date)
            ->where('status', 'LEAVE')
            ->countAllResults() > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION AND UTILITIES
    |--------------------------------------------------------------------------
    */

    private function validDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $date);

        return $d !== false &&
            $d->format('Y-m-d') === $date;
    }

    private function normalizeTime($value): ?string
    {
        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            return null;
        }

        $value = trim((string) $value);

        if (
            !preg_match(
                '/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
                $value
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid time format. Use HH:MM or HH:MM:SS.'
            );
        }

        return strlen($value) === 5
            ? $value . ':00'
            : $value;
    }

    private function calculateDuration(
        string $in,
        string $out
    ): int {
        $start = strtotime($in);
        $end = strtotime($out);

        if ($start === false || $end === false) {
            return 0;
        }

        // Support shifts crossing midnight.
        if ($end < $start) {
            $end += 86400;
        }

        return (int) floor(($end - $start) / 60);
    }

    private function jsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATION
    |--------------------------------------------------------------------------
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
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        return $session['user_id'] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT LOG
    |--------------------------------------------------------------------------
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
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
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