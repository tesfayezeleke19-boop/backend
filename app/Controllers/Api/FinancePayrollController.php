<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Throwable;

class FinancePayrollController extends ResourceController
{
    protected $format = 'json';

    private $db;

    private const FINANCE_STATUSES = [
        'PENDING_FINANCE_REVIEW',
        'FINANCE_APPROVED',
        'REJECTED',
        'PAID',
    ];

    private const PENSION_RATE = 0.07;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /* ------------------------------------------------------------------ */
    /* GET /api/finance/payroll-runs                                      */
    /* ------------------------------------------------------------------ */

    public function index()
    {
        try {
            $runs = $this->db->table('payroll_runs')
                ->whereIn('status', self::FINANCE_STATUSES)
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
            log_message('error', 'Finance payroll index error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError(
                'Unable to retrieve Finance payroll runs.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* GET /api/finance/payroll-runs/{id}                                 */
    /* ------------------------------------------------------------------ */

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
            log_message('error', 'Finance payroll show error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError(
                'Unable to retrieve payroll details.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* PUT /api/finance/payroll-runs/{id}/employees/{employeeRecordId}    */
    /* ------------------------------------------------------------------ */

    public function updateEmployee($id = null, $employeeRecordId = null)
    {
        if (!$id || !$employeeRecordId) {
            return $this->fail(
                'Payroll run ID and employee payroll record ID are required.',
                400
            );
        }

        $input = $this->getInput();

        if (
            !array_key_exists('allowance', $input) ||
            !array_key_exists('other_deduction', $input)
        ) {
            return $this->fail(
                'Allowance and other deduction are required.',
                422
            );
        }

        $allowance = $this->validatedAmount($input['allowance']);
        $otherDeduction = $this->validatedAmount(
            $input['other_deduction']
        );

        if ($allowance === null || $otherDeduction === null) {
            return $this->fail(
                'Allowance and other deduction must be valid non-negative numbers.',
                422
            );
        }

        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if (strtoupper($run['status']) === 'PAID') {
                $this->db->transRollback();

                return $this->fail(
                    'Paid payroll records cannot be edited.',
                    409
                );
            }

            $employee = $this->db->table('payroll_run_employees')
                ->where('id', $employeeRecordId)
                ->where('payroll_run_id', $run['id'])
                ->get()
                ->getRowArray();

            if (!$employee) {
                $this->db->transRollback();

                return $this->failNotFound(
                    'Employee payroll record not found.'
                );
            }

            $basicSalary = (float) $employee['basic_salary'];
            $overtimePay = (float) $employee['overtime_pay'];

            $grossSalary = round(
                $basicSalary + $allowance + $overtimePay,
                2
            );

            $incomeTax = $this->calculateIncomeTax($grossSalary);
            $pension = round($grossSalary * self::PENSION_RATE, 2);

            $totalDeduction = round(
                $incomeTax + $pension + $otherDeduction,
                2
            );

            $netPay = round($grossSalary - $totalDeduction, 2);

            $allowancesJson = json_encode([
                [
                    'name' => 'Allowance',
                    'amount' => $allowance,
                ],
            ], JSON_THROW_ON_ERROR);

            $deductionsJson = json_encode([
                [
                    'name' => 'Other Deduction',
                    'amount' => $otherDeduction,
                ],
            ], JSON_THROW_ON_ERROR);

            $this->db->table('payroll_run_employees')
                ->where('id', $employeeRecordId)
                ->where('payroll_run_id', $run['id'])
                ->update([
                    'allowances' => $allowancesJson,
                    'deductions' => $deductionsJson,
                    'gross_salary' => $grossSalary,
                    'net_salary' => $netPay,
                ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->failServerError(
                    'Unable to save the employee payroll changes.'
                );
            }

            $this->db->table('payroll_runs')
                ->where('id', $run['id'])
                ->update([
                    'updated_at' => date('Y-m-d H:i:s'),
                    'version' => ((int) ($run['version'] ?? 0)) + 1,
                ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->failServerError(
                    'Unable to update the payroll run.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Employee payroll updated successfully.',
                'data' => $this->getRunDetails($run['id']),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Finance payroll employee update error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError(
                'Unable to update employee payroll.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/finance/payroll-runs/{id}/approve                        */
    /* ------------------------------------------------------------------ */

    public function approve($id = null)
    {
        if (!$id) {
            return $this->fail('Payroll run ID is required.', 400);
        }

        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if ($run['status'] !== 'PENDING_FINANCE_REVIEW') {
                $this->db->transRollback();

                return $this->fail(
                    'Only payroll awaiting Finance review can be approved.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('payroll_runs')
                ->where('id', $run['id'])
                ->where('status', 'PENDING_FINANCE_REVIEW')
                ->update([
                    'status' => 'FINANCE_APPROVED',
                    'approved_at' => $now,
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            if (
                $this->db->transStatus() === false ||
                $this->db->affectedRows() !== 1
            ) {
                $this->db->transRollback();

                return $this->fail(
                    'Payroll approval could not be completed. Please refresh and try again.',
                    409
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Payroll approved successfully.',
                'data' => $this->getRunDetails($run['id']),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Finance payroll approval error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to approve payroll.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/finance/payroll-runs/{id}/reject                         */
    /* ------------------------------------------------------------------ */

    public function reject($id = null)
    {
        if (!$id) {
            return $this->fail('Payroll run ID is required.', 400);
        }

        $input = $this->getInput();
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            return $this->fail('A rejection reason is required.', 422);
        }

        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if ($run['status'] !== 'PENDING_FINANCE_REVIEW') {
                $this->db->transRollback();

                return $this->fail(
                    'Only payroll awaiting Finance review can be rejected.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('payroll_runs')
                ->where('id', $run['id'])
                ->where('status', 'PENDING_FINANCE_REVIEW')
                ->update([
                    'status' => 'REJECTED',
                    'rejection_reason' => $reason,
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            if (
                $this->db->transStatus() === false ||
                $this->db->affectedRows() !== 1
            ) {
                $this->db->transRollback();

                return $this->fail(
                    'Payroll rejection could not be completed. Please refresh and try again.',
                    409
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Payroll rejected successfully.',
                'data' => $this->getRunDetails($run['id']),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Finance payroll rejection error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError('Unable to reject payroll.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/finance/payroll-runs/{id}/mark-paid                      */
    /* ------------------------------------------------------------------ */

    public function markPaid($id = null)
    {
        if (!$id) {
            return $this->fail('Payroll run ID is required.', 400);
        }

        $this->db->transBegin();

        try {
            $run = $this->findRun($id);

            if (!$run) {
                $this->db->transRollback();
                return $this->failNotFound('Payroll run not found.');
            }

            if ($run['status'] !== 'FINANCE_APPROVED') {
                $this->db->transRollback();

                return $this->fail(
                    'Only Finance-approved payroll can be marked as paid.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('payroll_runs')
                ->where('id', $run['id'])
                ->where('status', 'FINANCE_APPROVED')
                ->update([
                    'status' => 'PAID',
                    'paid_at' => $now,
                    'updated_at' => $now,
                    'version' => ((int) $run['version']) + 1,
                ]);

            if (
                $this->db->transStatus() === false ||
                $this->db->affectedRows() !== 1
            ) {
                $this->db->transRollback();

                return $this->fail(
                    'Payroll payment status could not be updated. Please refresh and try again.',
                    409
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Payroll marked as paid successfully.',
                'data' => $this->getRunDetails($run['id']),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Finance payroll payment error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->failServerError(
                'Unable to mark payroll as paid.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* INPUT AND VALIDATION HELPERS                                       */
    /* ------------------------------------------------------------------ */

    private function getInput(): array
    {
        $input = $this->request->getJSON(true);

        if (!is_array($input)) {
            $input = $this->request->getPost();
        }

        return is_array($input) ? $input : [];
    }

    private function validatedAmount($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        if (!is_finite($amount) || $amount < 0) {
            return null;
        }

        return round($amount, 2);
    }

    /**
     * Progressive monthly employment income tax.
     */
    private function calculateIncomeTax(float $grossSalary): float
    {
        if ($grossSalary <= 2000) {
            return 0.00;
        }

        if ($grossSalary <= 4000) {
            $tax = ($grossSalary * 0.15) - 300;
        } elseif ($grossSalary <= 7000) {
            $tax = ($grossSalary * 0.20) - 500;
        } elseif ($grossSalary <= 10000) {
            $tax = ($grossSalary * 0.25) - 850;
        } elseif ($grossSalary <= 14000) {
            $tax = ($grossSalary * 0.30) - 1350;
        } else {
            $tax = ($grossSalary * 0.35) - 2050;
        }

        return round(max(0, $tax), 2);
    }

    /**
     * Accept either the human-readable payroll ID or database UUID.
     */
    private function findRun($identifier): ?array
    {
        if (empty($identifier)) {
            return null;
        }

        $identifier = trim((string) $identifier);

        $run = $this->db->table('payroll_runs')
            ->where('payroll_id', $identifier)
            ->get()
            ->getRowArray();

        if ($run) {
            return $run;
        }

        $isUuid = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $identifier
        );

        if (!$isUuid) {
            return null;
        }

        return $this->db->table('payroll_runs')
            ->where('id', $identifier)
            ->get()
            ->getRowArray() ?: null;
    }

    private function normalizeLineItems($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $items[] = [
                    'name' => (string) ($item['name'] ?? ''),
                    'amount' => (float) ($item['amount'] ?? 0),
                ];
            }
        } else {
            foreach ($value as $name => $item) {
                $items[] = [
                    'name' => (string) $name,
                    'amount' => is_array($item)
                        ? (float) ($item['amount'] ?? 0)
                        : (float) $item,
                ];
            }
        }

        return $items;
    }

    private function getLineItemTotal(array $items): float
    {
        return round(
            array_reduce(
                $items,
                fn ($total, $item) => $total + (float) ($item['amount'] ?? 0),
                0.0
            ),
            2
        );
    }

    /* ------------------------------------------------------------------ */
    /* BUILD PAYROLL RUN DETAILS                                          */
    /* ------------------------------------------------------------------ */

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
                $run['id'],
            ]
        )->getResultArray();

        $basicTotal = 0;
        $overtimeTotal = 0;
        $grossTotal = 0;
        $netTotal = 0;
        $overtimeHoursTotal = 0;
        $incomeTaxTotal = 0;
        $pensionTotal = 0;
        $otherDeductionTotal = 0;
        $totalDeductionTotal = 0;

        foreach ($employees as &$employee) {
            $employee['present_days'] = (int) $employee['present_days'];

            $employee['basic_salary'] = (float) $employee['basic_salary'];
            $employee['overtime_hours'] = (float) $employee['overtime_hours'];
            $employee['overtime_pay'] = (float) $employee['overtime_pay'];

            $employee['allowances'] = $this->normalizeLineItems(
                $employee['allowances'] ?? []
            );

            $employee['deductions'] = $this->normalizeLineItems(
                $employee['deductions'] ?? []
            );

            $allowance = $this->getLineItemTotal(
                $employee['allowances']
            );

            $otherDeduction = $this->getLineItemTotal(
                $employee['deductions']
            );

            $gross = round(
                $employee['basic_salary'] +
                $allowance +
                $employee['overtime_pay'],
                2
            );

            $incomeTax = $this->calculateIncomeTax($gross);
            $pension = round($gross * self::PENSION_RATE, 2);

            $totalDeduction = round(
                $incomeTax + $pension + $otherDeduction,
                2
            );

            $netPay = round($gross - $totalDeduction, 2);

            $employee['allowance_total'] = $allowance;
            $employee['other_deduction'] = $otherDeduction;
            $employee['gross_salary'] = $gross;
            $employee['income_tax'] = $incomeTax;
            $employee['pension'] = $pension;
            $employee['total_deductions'] = $totalDeduction;
            $employee['net_salary'] = $netPay;

            $basicTotal += $employee['basic_salary'];
            $overtimeTotal += $employee['overtime_pay'];
            $overtimeHoursTotal += $employee['overtime_hours'];

            $grossTotal += $gross;
            $incomeTaxTotal += $incomeTax;
            $pensionTotal += $pension;
            $otherDeductionTotal += $otherDeduction;
            $totalDeductionTotal += $totalDeduction;
            $netTotal += $netPay;
        }

        unset($employee);

        $run['overtime_rate'] = (float) ($run['overtime_rate'] ?? 0);

        $run['employees'] = $employees;
        $run['employee_records'] = $employees;

        $run['totals'] = [
            'employee_count' => count($employees),
            'basic_salary' => round($basicTotal, 2),
            'overtime_hours' => round($overtimeHoursTotal, 2),
            'overtime_pay' => round($overtimeTotal, 2),
            'gross_salary' => round($grossTotal, 2),
            'income_tax' => round($incomeTaxTotal, 2),
            'pension' => round($pensionTotal, 2),
            'other_deduction' => round($otherDeductionTotal, 2),
            'deductions' => round($totalDeductionTotal, 2),
            'total_deductions' => round($totalDeductionTotal, 2),
            'net_salary' => round($netTotal, 2),
            'total_amount' => round($netTotal, 2),
        ];

        return $run;
    }
}
