<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTime;

class FinanceReportController extends ResourceController
{
    protected $format = 'json';

    private $db;
    private ?array $authenticatedUser = null;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);

        $this->db = Database::connect();
    }

    /**
     * Validate the bearer token using the existing user_sessions system.
     */
    private function authenticate(): bool
    {
        $authorization = $this->request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            $this->failUnauthorized('A valid bearer token is required.');
            return false;
        }

        $token = trim($matches[1]);
        $tokenHash = hash('sha256', $token);

        $session = $this->db->table('user_sessions us')
            ->select('us.id AS session_id, us.user_id')
            ->join('users u', 'u.id = us.user_id')
            ->where('us.token_hash', $tokenHash)
            ->where('us.revoked_at IS NULL', null, false)
            ->where('us.expires_at > NOW()', null, false)
            ->where('LOWER(u.account_status)', 'active')
            ->get()
            ->getRowArray();

        if (!$session) {
            $this->failUnauthorized('Your session is invalid or expired.');
            return false;
        }

        $this->authenticatedUser = [
            'id' => $session['user_id'],
            'session_id' => $session['session_id'],
        ];

        $this->db->table('user_sessions')
            ->where('id', $session['session_id'])
            ->update([
                'last_used_at' => date('Y-m-d H:i:s'),
            ]);

        return true;
    }

    /**
     * Validate the requested date interval.
     */
    private function validateDateRange(): ?array
    {
        $startDate = trim((string) $this->request->getGet('start_date'));
        $endDate = trim((string) $this->request->getGet('end_date'));

        if (!$this->isValidDate($startDate)) {
            $this->failValidationErrors(
                'A valid start_date is required. Use YYYY-MM-DD format.'
            );
            return null;
        }

        if (!$this->isValidDate($endDate)) {
            $this->failValidationErrors(
                'A valid end_date is required. Use YYYY-MM-DD format.'
            );
            return null;
        }

        if ($startDate > $endDate) {
            $this->failValidationErrors(
                'The start_date cannot be later than the end_date.'
            );
            return null;
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * Check whether a date exactly matches YYYY-MM-DD.
     */
    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $date);

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    }

    /**
     * GET /api/finance/reports/sales
     *
     * Fetch sales invoice lines within the selected invoice_date interval.
     * Each returned record represents one aggregate/order line, not one invoice.
     */
    public function sales(): ResponseInterface
    {
        if (!$this->authenticate()) {
            return $this->response;
        }

        $dateRange = $this->validateDateRange();

        if ($dateRange === null) {
            return $this->response;
        }

        try {
            $startDate = $dateRange['start_date'];
            $endDate = $dateRange['end_date'];

            /*
             * WHT is stored at invoice level. Allocate it to each invoice line
             * according to that line's share of the invoice subtotal.
             *
             * sales_invoice_lines.aggregate is the aggregate type text.
             * It is returned directly as aggregate_type.
             */
            $sql = "
                WITH invoice_lines AS (
                    SELECT
                        sil.id,
                        sil.invoice_id,
                        sil.aggregate,
                        sil.quantity,
                        sil.unit,
                        COALESCE(sil.line_total_excl_vat, 0) AS line_subtotal_excl_vat,
                        si.invoice_number,
                        si.invoice_date,
                        si.status,
                        COALESCE(si.vat_rate, 0) AS vat_rate,
                        COALESCE(si.withholding_amount, 0) AS invoice_withholding_amount,
                        SUM(COALESCE(sil.line_total_excl_vat, 0))
                            OVER (PARTITION BY sil.invoice_id) AS invoice_subtotal_excl_vat
                    FROM sales_invoice_lines sil
                    INNER JOIN sales_invoices si
                        ON si.id = sil.invoice_id
                    WHERE si.invoice_date >= ?
                      AND si.invoice_date <= ?
                )
                SELECT
                    id,
                    invoice_id,
                    invoice_number,
                    invoice_date,
                    status,
                    COALESCE(NULLIF(TRIM(aggregate), ''), 'Unspecified') AS aggregate_type,
                    COALESCE(quantity, 0) AS quantity,
                    COALESCE(unit, 'm3') AS unit,
                    line_subtotal_excl_vat AS subtotal_excl_vat,
                    line_subtotal_excl_vat * vat_rate AS total_vat,
                    line_subtotal_excl_vat
                        + (line_subtotal_excl_vat * vat_rate) AS total_price_incl_vat,
                    CASE
                        WHEN invoice_subtotal_excl_vat > 0 THEN
                            invoice_withholding_amount
                            * line_subtotal_excl_vat
                            / invoice_subtotal_excl_vat
                        ELSE 0
                    END AS withholding_amount
                FROM invoice_lines
                ORDER BY invoice_date ASC, invoice_number ASC, id ASC
            ";

            $records = $this->db->query($sql, [$startDate, $endDate])->getResultArray();

            $summary = $this->db->query(
                "
                SELECT
                    COUNT(*) AS record_count,
                    COALESCE(SUM(line_subtotal_excl_vat), 0) AS subtotal_excl_vat,
                    COALESCE(SUM(line_vat), 0) AS total_vat,
                    COALESCE(SUM(line_total), 0) AS total_price_incl_vat,
                    COALESCE(SUM(line_withholding), 0) AS withholding_amount
                FROM (
                    SELECT
                        COALESCE(sil.line_total_excl_vat, 0) AS line_subtotal_excl_vat,
                        COALESCE(sil.line_total_excl_vat, 0) * COALESCE(si.vat_rate, 0) AS line_vat,
                        COALESCE(sil.line_total_excl_vat, 0)
                            * (1 + COALESCE(si.vat_rate, 0)) AS line_total,
                        CASE
                            WHEN invoice_totals.invoice_subtotal_excl_vat > 0 THEN
                                COALESCE(si.withholding_amount, 0)
                                * COALESCE(sil.line_total_excl_vat, 0)
                                / invoice_totals.invoice_subtotal_excl_vat
                            ELSE 0
                        END AS line_withholding
                    FROM sales_invoice_lines sil
                    INNER JOIN sales_invoices si
                        ON si.id = sil.invoice_id
                    INNER JOIN (
                        SELECT
                            invoice_id,
                            SUM(COALESCE(line_total_excl_vat, 0)) AS invoice_subtotal_excl_vat
                        FROM sales_invoice_lines
                        GROUP BY invoice_id
                    ) invoice_totals
                        ON invoice_totals.invoice_id = sil.invoice_id
                    WHERE si.invoice_date >= ?
                      AND si.invoice_date <= ?
                ) report_lines
                ",
                [$startDate, $endDate]
            )->getRowArray();

            return $this->respond([
                'success' => true,
                'message' => 'Sales report retrieved successfully.',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'records' => $records,
                    'summary' => [
                        'record_count' => (int) ($summary['record_count'] ?? 0),
                        'subtotal_excl_vat' => $summary['subtotal_excl_vat'] ?? '0',
                        'total_vat' => $summary['total_vat'] ?? '0',
                        'total_price_incl_vat' => $summary['total_price_incl_vat'] ?? '0',
                        'withholding_amount' => $summary['withholding_amount'] ?? '0',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Finance sales report error: ' . $e->getMessage());

            return $this->failServerError(
                'Unable to retrieve the sales report.'
            );
        }
    }

    /**
     * GET /api/finance/reports/expenses
     *
     * Fetch expenses within the selected expense_date interval.
     */
    public function expenses(): ResponseInterface
    {
        if (!$this->authenticate()) {
            return $this->response;
        }

        $dateRange = $this->validateDateRange();

        if ($dateRange === null) {
            return $this->response;
        }

        try {
            $startDate = $dateRange['start_date'];
            $endDate = $dateRange['end_date'];

            $fields = [
                'id',
                'expense_number',
                'expense_date',
                // Actual expense type/name; expense_category is a separate classification.
                'expense_type',
                'description',
                'supplier_name',
                'supplier_tin',
                'amount_excl_vat',
                'vat_enabled',
                'vat_rate',
                'vat_amount',
                'wht_enabled',
                'wht_rate',
                'wht_amount',
                'total_amount',
                'status',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
                'paid_at',
                'paid_by',
                'attachment_id',
                'created_by',
                'created_at',
                'updated_at',
                'version',
                'expense_source',
                'bank',
                'payment_reference',
                'payment_party',
                'remark',
                'wht_attachment_id',
                'review_status',
                'expense_category',
                'total_transferred',
                'invoice_attachment_id',
                'approved_by',
                'approved_at',
                'rejected_at',
            ];

            $records = $this->db->table('expenses')
                ->select($fields)
                ->where('expense_date >=', $startDate)
                ->where('expense_date <=', $endDate)
                ->orderBy('expense_date', 'ASC')
                ->orderBy('expense_number', 'ASC')
                ->get()
                ->getResultArray();

            $summary = $this->db->table('expenses')
                ->select(
                    "
                    COUNT(*) AS record_count,
                    COALESCE(SUM(amount_excl_vat), 0) AS amount_excl_vat,
                    COALESCE(SUM(vat_amount), 0) AS vat_amount,
                    COALESCE(SUM(wht_amount), 0) AS wht_amount,
                    COALESCE(SUM(total_amount), 0) AS total_amount,
                    COALESCE(SUM(total_transferred), 0) AS total_transferred
                    ",
                    false
                )
                ->where('expense_date >=', $startDate)
                ->where('expense_date <=', $endDate)
                ->get()
                ->getRowArray();

            return $this->respond([
                'success' => true,
                'message' => 'Expenses report retrieved successfully.',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'records' => $records,
                    'summary' => [
                        'record_count' => (int) ($summary['record_count'] ?? 0),
                        'amount_excl_vat' => $summary['amount_excl_vat'] ?? '0',
                        'vat_amount' => $summary['vat_amount'] ?? '0',
                        'wht_amount' => $summary['wht_amount'] ?? '0',
                        'total_amount' => $summary['total_amount'] ?? '0',
                        'total_transferred' => $summary['total_transferred'] ?? '0',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Finance expenses report error: ' . $e->getMessage());

            return $this->failServerError(
                'Unable to retrieve the expenses report.'
            );
        }
    }
}