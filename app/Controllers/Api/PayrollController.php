<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Throwable;

class PayrollController extends ResourceController
{
    protected $format = 'json';

    private $db;

    private const MONTHLY_HOURS_DIVISOR = 208;

    private const EDITABLE_STATUSES = ['DRAFT', 'REJECTED'];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /* ---------------------------------------------------------------------- */
    /* GET /api/hr/payroll                                                     */
    /* ---------------------------------------------------------------------- */

    public function index()
{
    try {
        $month = $this->request->getGet('period_month');
        $year = $this->request->getGet('period_year');

        $builder = $this->db->table('payroll_runs');

        if ($month !== null && $month !== '') {
            $month = (int) $month;

            if ($month < 1 || $month > 13) {
                return $this->fail('Invalid payroll month.', 400);
            }

            $builder->where('period_month', $month);
        }

        if ($year !== null && $year !== '') {
            $year = (int) $year;

            if ($year < 2000 || $year > 2200) {
                return $this->fail('Invalid payroll year.', 400);
            }

            $builder->where('period_year', $year);
        }

        $runs = $builder
            ->orderBy('period_year', 'DESC')
            ->orderBy('period_month', 'DESC')
            ->get()
            ->getResultArray();

        $result = [];

        foreach ($runs as $run) {
            $details = $this->getRunDetails($run['id']);

            if ($details) {
                $result[] = $details;
            }
        }

        return $this->respond([
            'success' => true,
            'data' => $result,
        ]);

    } catch (Throwable $e) {
        log_message('error', 'Payroll index error: {message}', [
            'message' => $e->getMessage(),
        ]);

        return $this->failServerError(
            'Unable to retrieve payroll runs.'
        );
    }
}

    /* ---------------------------------------------------------------------- */
    /* GET /api/hr/payroll/config                                              */
    /* ---------------------------------------------------------------------- */

    public function getConfig()
    {
        try {
            $rate = $this->getOvertimeRate();

            return $this->respond([
                'success' => true,
                'data' => [
                    'overtime_rate' => $rate,
                    'monthly_hours_divisor' => self::MONTHLY_HOURS_DIVISOR,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Payroll config error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to retrieve payroll configuration.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* PUT /api/hr/payroll/config                                              */
    /* ---------------------------------------------------------------------- */

    public function updateConfig()
    {
        try {
            $input = $this->getInput();

            if (!isset($input['overtime_rate']) || !is_numeric($input['overtime_rate'])) {
                return $this->fail('A valid overtime rate is required.', 422);
            }

            $rate = (float) $input['overtime_rate'];

            if ($rate < 0 || $rate > 1000) {
                return $this->fail('Overtime rate must be between 0 and 1000.', 422);
            }

            $setting = $this->db->table('operational_settings')
                ->where('setting_key', 'overtime_rate')
                ->get()
                ->getRowArray();

            if (!$setting) {
                return $this->failNotFound('Overtime rate setting was not found.');
            }

            $this->db->table('operational_settings')
                ->where('setting_key', 'overtime_rate')
                ->update([
                    'setting_value' => $rate,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            // Keep the existing one-row payroll config table synchronized.
            $config = $this->db->table('payroll_configs')
                ->where('id', 1)
                ->get()
                ->getRowArray();

            if ($config) {
                $this->db->table('payroll_configs')
                    ->where('id', 1)
                    ->update([
                        'overtime_rate' => $rate,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                $this->db->table('payroll_configs')->insert([
                    'id' => 1,
                    'overtime_rate' => $rate,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Payroll overtime rate updated successfully.',
                'data' => [
                    'overtime_rate' => $rate,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Payroll config update error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to update payroll configuration.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* POST /api/hr/payroll                                                    */
    /* Create a monthly payroll run                                           */
    /* ---------------------------------------------------------------------- */

    public function create()
    {
        try {
            $input = $this->getInput();

            $month = (int) ($input['period_month'] ?? 0);
            $year = (int) ($input['period_year'] ?? 0);

            if ($month < 1 || $month > 13) {
                return $this->fail('A valid payroll month is required.', 422);
            }

            if ($year < 2000 || $year > 2200) {
                return $this->fail('A valid payroll year is required.', 422);
            }

            // Resolve the Ethiopian payroll month to its Gregorian date range.
            // The database continues to store real Gregorian DATE values.
            [$startDate, $endDate] = $this->ethiopianMonthDateRange($year, $month);

            // A payroll period is in the future only when its first day is
            // later than today's Gregorian date.
            if ($startDate > date('Y-m-d')) {
                return $this->fail('Payroll cannot be generated for a future month.', 422);
            }

            $existing = $this->db->table('payroll_runs')
                ->where('period_month', $month)
                ->where('period_year', $year)
                ->get()
                ->getRowArray();

            if ($existing) {
                return $this->respond([
                    'success' => true,
                    'message' => 'Payroll already exists for this month.',
                    'data' => $this->getRunDetails($existing['id']),
                ]);
            }

            $rate = $this->getOvertimeRate();

            $id = $this->uuid();
            $now = date('Y-m-d H:i:s');

            $payrollId = sprintf(
                'PAY-%04d%02d-%s',
                $year,
                $month,
                strtoupper(substr(str_replace('-', '', $id), 0, 6))
            );

            $this->db->table('payroll_runs')->insert([
                'id' => $id,
                'payroll_id' => $payrollId,
                'period_label' => $this->ethiopianMonthName($month) . ' ' . $year,
                'period_month' => $month,
                'period_year' => $year,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'DRAFT',
                'overtime_rate' => $rate,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]);

            return $this->respondCreated([
                'success' => true,
                'message' => 'Payroll run created successfully.',
                'data' => $this->getRunDetails($id),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Payroll create error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to create payroll run.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* GET /api/hr/payroll/{id}                                                */
    /* ---------------------------------------------------------------------- */

    public function show($id = null)
    {
        try {
            if (!$id) {
                return $this->fail('Payroll run ID is required.', 400);
            }

            $run = $this->getRunDetails($id);

            if (!$run) {
                return $this->failNotFound('Payroll run not found.');
            }

            return $this->respond([
                'success' => true,
                'data' => $run,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Payroll show error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to retrieve payroll details.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* POST /api/hr/payroll/{id}/calculate                                    */
    /* ---------------------------------------------------------------------- */

    public function calculate($id = null)
    {
        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if (!in_array($run['status'], self::EDITABLE_STATUSES, true)) {
                $this->db->transRollback();

                return $this->fail(
                    'Only draft or rejected payroll can be recalculated.',
                    409
                );
            }

            $startDate = $run['start_date'];
            $endDate = $run['end_date'];

            $rate = (float) $run['overtime_rate'];
            $now = date('Y-m-d H:i:s');

            /*
             * Get employees who were hired by the end of the payroll month.
             *
             * Attendance aggregation:
             * - Present days count only PRESENT records.
             * - Overtime comes from saved attendance_records.
             * - Overtime is included only when both clock_in and clock_out exist.
             */

            $sql = "
                SELECT
                    e.id AS employee_id,
                    e.employee_id AS employee_code,
                    e.machine_employee_no,
                    e.full_name,
                    e.salary AS basic_salary,

                    COUNT(a.id) FILTER (
                        WHERE UPPER(a.status) = 'PRESENT'
                    ) AS present_days,

                    COALESCE(
                        SUM(
                            CASE
                                WHEN a.clock_in IS NOT NULL
                                 AND a.clock_out IS NOT NULL
                                 AND UPPER(a.status) IN ('PRESENT', 'LATE')
                                THEN COALESCE(a.overtime_hours, 0)
                                ELSE 0
                            END
                        ),
                        0
                    ) AS overtime_hours

                FROM employees e

                LEFT JOIN attendance_records a
                    ON a.employee_id = e.id
                    AND a.attendance_date >= ?
                    AND a.attendance_date <= ?

                WHERE e.hired_date <= ?

                GROUP BY
                    e.id,
                    e.employee_id,
                    e.machine_employee_no,
                    e.full_name,
                    e.salary

                ORDER BY e.full_name ASC
            ";

            $employees = $this->db->query(
                $sql,
                [$startDate, $endDate, $endDate]
            )->getResultArray();

            if (empty($employees)) {
                $this->db->transRollback();

                return $this->fail(
                    'No employees were found for this payroll period.',
                    422
                );
            }

            // Remove the previous draft calculation.
            $this->db->table('payroll_run_employees')
                ->where('payroll_run_id', $id)
                ->delete();

            foreach ($employees as $employee) {
                $basicSalary = max(0, (float) $employee['basic_salary']);
                $overtimeHours = max(0, (float) $employee['overtime_hours']);

                $hourlyRate = $basicSalary / self::MONTHLY_HOURS_DIVISOR;

                $overtimePay = round(
                    $overtimeHours * $hourlyRate * ($rate / 100),
                    2
                );

                $grossSalary = round($basicSalary + $overtimePay, 2);

                $this->db->table('payroll_run_employees')->insert([
                    'id' => $this->uuid(),
                    'payroll_run_id' => $id,
                    'employee_id' => $employee['employee_id'],
                    'basic_salary' => $basicSalary,
                    'overtime_hours' => $overtimeHours,
                    'overtime_pay' => $overtimePay,
                    'gross_salary' => $grossSalary,
                    'net_salary' => $grossSalary,
                    'allowances' => json_encode(new \stdClass()),
                    'deductions' => json_encode(new \stdClass()),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->db->table('payroll_runs')
                ->where('id', $id)
                ->update([
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->failServerError(
                    'Payroll calculation could not be saved.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Payroll calculated successfully.',
                'data' => $this->getRunDetails($id),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Payroll calculate error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to calculate payroll.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* PUT /api/hr/payroll/{id}/employees/{employeeId}                         */
    /* ---------------------------------------------------------------------- */

    public function updateEmployee($id = null, $employeeId = null)
    {
        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if (!in_array($run['status'], self::EDITABLE_STATUSES, true)) {
                $this->db->transRollback();

                return $this->fail(
                    'This payroll has been submitted and cannot be edited.',
                    409
                );
            }

            $input = $this->getInput();

            if (
                !isset($input['basic_salary']) ||
                !isset($input['overtime_hours']) ||
                !is_numeric($input['basic_salary']) ||
                !is_numeric($input['overtime_hours'])
            ) {
                $this->db->transRollback();

                return $this->fail(
                    'Basic salary and overtime hours are required.',
                    422
                );
            }

            $basicSalary = (float) $input['basic_salary'];
            $overtimeHours = (float) $input['overtime_hours'];

            if ($basicSalary < 0 || $overtimeHours < 0) {
                $this->db->transRollback();

                return $this->fail(
                    'Salary and overtime hours cannot be negative.',
                    422
                );
            }

            $row = $this->db->table('payroll_run_employees')
                ->where('payroll_run_id', $id)
                ->where('employee_id', $employeeId)
                ->get()
                ->getRowArray();

            if (!$row) {
                $this->db->transRollback();

                return $this->failNotFound(
                    'Employee payroll record was not found.'
                );
            }

            $hourlyRate = $basicSalary / self::MONTHLY_HOURS_DIVISOR;

            $overtimePay = round(
                $overtimeHours *
                $hourlyRate *
                ((float) $run['overtime_rate'] / 100),
                2
            );

            $grossSalary = round($basicSalary + $overtimePay, 2);

            $now = date('Y-m-d H:i:s');

            $this->db->table('payroll_run_employees')
                ->where('id', $row['id'])
                ->update([
                    'basic_salary' => $basicSalary,
                    'overtime_hours' => $overtimeHours,
                    'overtime_pay' => $overtimePay,
                    'gross_salary' => $grossSalary,
                    'net_salary' => $grossSalary,
                    'updated_at' => $now,
                ]);

            $this->db->table('payroll_runs')
                ->where('id', $id)
                ->update([
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->failServerError(
                    'Employee payroll changes could not be saved.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Employee payroll updated successfully.',
                'data' => $this->getRunDetails($id),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Payroll employee update error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError(
                'Unable to update employee payroll.'
            );
        }
    }

    /* ---------------------------------------------------------------------- */
    /* POST /api/hr/payroll/{id}/submit                                        */
    /* ---------------------------------------------------------------------- */

    public function submit($id = null)
    {
        try {
            $run = $this->findRun($id);

            if (!$run) {
                return $this->failNotFound('Payroll run not found.');
            }

            if (!in_array($run['status'], self::EDITABLE_STATUSES, true)) {
                return $this->fail(
                    'This payroll has already been submitted or finalized.',
                    409
                );
            }

            $count = $this->db->table('payroll_run_employees')
                ->where('payroll_run_id', $id)
                ->countAllResults();

            if ($count < 1) {
                return $this->fail(
                    'Generate payroll before submitting it.',
                    422
                );
            }

            $total = $this->db->table('payroll_run_employees')
                ->selectSum('gross_salary', 'total_amount')
                ->where('payroll_run_id', $id)
                ->get()
                ->getRowArray();

            $now = date('Y-m-d H:i:s');

            /*
             * SUBMITTED is the Finance handoff state.
             * The existing Finance payroll workflow can now receive it.
             */

            $this->db->table('payroll_runs')
                ->where('id', $id)
                ->update([
                    'status' => 'PENDING_FINANCE_REVIEW',
                    'submitted_at' => $now,
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            return $this->respond([
                
    'success' => true,
    'message' => 'Payroll submitted to Finance successfully.',
    'data' => $this->getRunDetails($id),
]);
          
        } catch (Throwable $e) {
            log_message('error', 'Payroll submit error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to submit payroll.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* POST /api/hr/payroll/{id}/resubmit                                      */
    /* ---------------------------------------------------------------------- */

    public function resubmit($id = null)
    {
        try {
            $run = $this->findRun($id);

            if (!$run) {
                return $this->failNotFound('Payroll run not found.');
            }

            if ($run['status'] !== 'REJECTED') {
                return $this->fail(
                    'Only rejected payroll can be resubmitted.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('payroll_runs')
                ->where('id', $id)
                ->update([
                    'status' => 'DRAFT',
                    'submitted_by' => null,
                    'submitted_at' => null,
                    'rejection_reason' => null,
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            return $this->respond([
                'success' => true,
                'message' => 'Rejected payroll returned to draft.',
                'data' => $this->getRunDetails($id),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Payroll resubmit error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to reopen rejected payroll.');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Internal helpers                                                        */
    /* ---------------------------------------------------------------------- */

    private function getInput(): array
    {
        $input = $this->request->getJSON(true);

        if (!is_array($input)) {
            $input = $this->request->getPost();
        }

        return is_array($input) ? $input : [];
    }

    private function findRun($id): ?array
    {
        if (!$id) {
            return null;
        }

        $run = $this->db->table('payroll_runs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        return $run ?: null;
    }

    private function getOvertimeRate(): float
    {
        $setting = $this->db->table('operational_settings')
            ->select('setting_value')
            ->where('setting_key', 'overtime_rate')
            ->get()
            ->getRowArray();

        if (!$setting) {
            throw new \RuntimeException(
                'The overtime_rate operational setting was not found.'
            );
        }

        $rate = (float) $setting['setting_value'];

        if ($rate < 0) {
            throw new \RuntimeException(
                'The configured overtime rate cannot be negative.'
            );
        }

        return $rate;
    }

    private function getRunDetails(string $id): ?array
{
    $run = $this->findRun($id);

    if (!$run) {
        return null;
    }

    $sql = "
        SELECT
            pre.id,
            pre.payroll_run_id,
            pre.employee_id,

            e.employee_id AS employee_code,
            e.machine_employee_no,
            e.full_name,

            pre.basic_salary,
            pre.overtime_hours,
            pre.overtime_pay,
            pre.gross_salary,
            pre.net_salary,

            pre.allowances,
            pre.deductions,

            (
                SELECT COUNT(*)
                FROM attendance_records a
                WHERE a.employee_id = e.id
                  AND a.attendance_date >= ?
                  AND a.attendance_date <= ?
                  AND UPPER(a.status) = 'PRESENT'
            ) AS present_days

        FROM payroll_run_employees pre

        INNER JOIN employees e
            ON e.id = pre.employee_id

        WHERE pre.payroll_run_id = ?

        ORDER BY e.full_name ASC
    ";

    $employees = $this->db->query(
        $sql,
        [
            $run['start_date'],
            $run['end_date'],
            $id,
        ]
    )->getResultArray();

    $basicTotal = 0;
    $overtimeTotal = 0;
    $grossTotal = 0;
    $netTotal = 0;
    $overtimeHoursTotal = 0;
    $deductionsTotal = 0;

    foreach ($employees as &$employee) {

        $employee['present_days'] = (int) $employee['present_days'];

        $employee['basic_salary'] = (float) $employee['basic_salary'];

        $employee['overtime_hours'] = (float) $employee['overtime_hours'];

        $employee['overtime_pay'] = (float) $employee['overtime_pay'];

        $employee['gross_salary'] = (float) $employee['gross_salary'];

        $employee['net_salary'] = (float) $employee['net_salary'];

        // Decode JSON allowances safely.
        $allowances = $employee['allowances'] ?? [];

        if (is_string($allowances)) {
            $allowances = json_decode($allowances, true);
        }

        if (!is_array($allowances)) {
            $allowances = [];
        }

        // The frontend expects an array.
        if (array_is_list($allowances)) {
            $employee['allowances'] = $allowances;
        } else {
            $employee['allowances'] = array_values($allowances);
        }

        // Decode JSON deductions safely.
        $deductions = $employee['deductions'] ?? [];

        if (is_string($deductions)) {
            $deductions = json_decode($deductions, true);
        }

        if (!is_array($deductions)) {
            $deductions = [];
        }

        if (array_is_list($deductions)) {
            $employee['deductions'] = $deductions;
        } else {
            $employee['deductions'] = array_values($deductions);
        }

        // Normalize allowance and deduction amounts.
        foreach ($employee['allowances'] as &$allowance) {
            if (is_array($allowance)) {
                $allowance['amount'] = (float) ($allowance['amount'] ?? 0);
            }
        }

        unset($allowance);

        foreach ($employee['deductions'] as &$deduction) {
            if (is_array($deduction)) {
                $deduction['amount'] = (float) ($deduction['amount'] ?? 0);
            }
        }

        unset($deduction);

        $basicTotal += $employee['basic_salary'];
        $overtimeTotal += $employee['overtime_pay'];
        $grossTotal += $employee['gross_salary'];
        $netTotal += $employee['net_salary'];
        $overtimeHoursTotal += $employee['overtime_hours'];

        foreach ($employee['deductions'] as $deduction) {
            $deductionsTotal += (float) ($deduction['amount'] ?? 0);
        }
    }

    unset($employee);

    $run['overtime_rate'] = (float) $run['overtime_rate'];

    // Preserve the existing HR response property.
    $run['employees'] = $employees;

    // Add the property expected by Finance.
    $run['employee_records'] = $employees;

    $run['totals'] = [
        'employee_count' => count($employees),
        'basic_salary' => round($basicTotal, 2),
        'overtime_hours' => round($overtimeHoursTotal, 2),
        'overtime_pay' => round($overtimeTotal, 2),
        'gross_salary' => round($grossTotal, 2),
        'deductions' => round($deductionsTotal, 2),
        'net_salary' => round($netTotal, 2),
        'total_amount' => round($grossTotal, 2),
    ];

    return $run;
}

    /**
     * Convert an Ethiopian calendar month into its Gregorian date boundaries.
     *
     * Ethiopian calendar months 1-12 contain 30 days. Pagume (month 13)
     * contains 5 days, or 6 days when the Ethiopian year is a leap year.
     *
     * The returned values are Gregorian YYYY-MM-DD strings for PostgreSQL.
     */
    private function ethiopianMonthDateRange(int $year, int $month): array
    {
        if ($year < 1 || $month < 1 || $month > 13) {
            throw new \InvalidArgumentException('Invalid Ethiopian calendar month or year.');
        }

        $startJdn = $this->ethiopianDateToJdn($year, $month, 1);

        if ($month === 13) {
            $nextStartJdn = $this->ethiopianDateToJdn($year + 1, 1, 1);
        } else {
            $nextStartJdn = $this->ethiopianDateToJdn($year, $month + 1, 1);
        }

        return [
            $this->jdnToGregorianDate($startJdn),
            $this->jdnToGregorianDate($nextStartJdn - 1),
        ];
    }

    /**
     * Ethiopian calendar date to Julian Day Number.
     * The Ethiopian epoch constant is 1724220.
     */
    private function ethiopianDateToJdn(int $year, int $month, int $day): int
    {
        return 1724220
            + (365 * ($year - 1))
            + intdiv($year, 4)
            + (30 * ($month - 1))
            + $day;
    }

    private function jdnToGregorianDate(int $jdn): string
    {
        // Gregorian 1970-01-01 has JDN 2440588.
        $timestamp = ($jdn - 2440588) * 86400;

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d');
    }

    private function ethiopianMonthName(int $month): string
    {
        $months = [
            1 => 'Meskerem',
            2 => 'Tikimt',
            3 => 'Hidar',
            4 => 'Tahsas',
            5 => 'Tir',
            6 => 'Yekatit',
            7 => 'Megabit',
            8 => 'Miazia',
            9 => 'Ginbot',
            10 => 'Sene',
            11 => 'Hamle',
            12 => 'Nehase',
            13 => 'Pagume',
        ];

        return $months[$month] ?? 'Unknown';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
