<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Admin financial reports.
 *
 * Read-only reporting endpoint. It reads the existing Sales, Expenses,
 * Financial Records and Payroll tables; it does not create or modify records.
 * Financial formulas intentionally follow the shared Finance rules:
 *   VAT        = Total Sales - (Total Sales / 1.15)
 *   Gross      = Total Sales - VAT
 *   Royalty    = 3% of VAT-exclusive approved invoice-line value
 *   Net Profit = Gross Income - Counted Expenses - Royalty
 *
 * Expense counting rule:
 *   FINANCE and LEGACY expenses are counted.
 *   ADMIN expenses are counted only when Finance has approved them.
 */
class AdminFinancialReportsController extends BaseController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    private const SALES_STATUSES = [
        'PENDING_FINANCE_REVIEW',
        'FINANCE_APPROVED',
        'PAYMENT_CONFIRMED',
    ];

    private const ROYALTY_STATUSES = [
        'FINANCE_APPROVED',
        'PAYMENT_CONFIRMED',
    ];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function respondJson(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function fail(string $message, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => false,
            'message' => $message,
        ]);
    }

    /** Same bearer-session authentication used by AdminExpensesController. */
    private function authenticate(): bool
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return false;
        }

        $tokenHash = hash('sha256', trim($matches[1]));
        $session = $this->db->table('user_sessions')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->where('expires_at >', $this->now())
            ->get()
            ->getRowArray();

        if (!$session) {
            return false;
        }

        $user = $this->db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (!$user || strtolower((string) ($user['account_status'] ?? '')) !== 'active') {
            return false;
        }

        try {
            $this->db->table('user_sessions')
                ->where('id', $session['id'])
                ->update(['last_used_at' => $this->now()]);
        } catch (Throwable $ignored) {
            // Do not fail a report read if optional session activity logging fails.
        }

        $this->authenticatedUser = $user;
        return true;
    }

    private function authorizeAdmin(): ?ResponseInterface
    {
        if (!$this->authenticate()) {
            return $this->fail('Authentication required.', 401);
        }

        if (strtoupper(trim((string) ($this->authenticatedUser['role'] ?? ''))) !== 'ADMIN') {
            return $this->fail('Only Admin users can access this report.', 403);
        }

        return null;
    }

    /** Validate optional year/month query parameters. */
    private function readFilters(): array
    {
        $yearValue = $this->request->getGet('year');
        $monthValue = $this->request->getGet('month');

        $year = null;
        $month = null;

        if ($yearValue !== null && $yearValue !== '') {
            if (!ctype_digit((string) $yearValue)) {
                throw new \InvalidArgumentException('Year must be a valid four-digit year.');
            }
            $year = (int) $yearValue;
            if ($year < 2000 || $year > 2200) {
                throw new \InvalidArgumentException('Year is outside the supported range.');
            }
        }

        if ($monthValue !== null && $monthValue !== '') {
            if (!ctype_digit((string) $monthValue)) {
                throw new \InvalidArgumentException('Month must be a number from 1 to 12.');
            }
            $month = (int) $monthValue;
            if ($month < 1 || $month > 12) {
                throw new \InvalidArgumentException('Month must be between 1 and 12.');
            }
        }

        return ['year' => $year, 'month' => $month];
    }

    /** Apply year/month filtering to a date expression using bound values. */
    private function dateFilterSql(string $dateExpression, array $filters, array &$binds): string
    {
        $sql = '';
        if ($filters['year'] !== null) {
            $sql .= " AND EXTRACT(YEAR FROM {$dateExpression}) = ?";
            $binds[] = $filters['year'];
        }
        if ($filters['month'] !== null) {
            $sql .= " AND EXTRACT(MONTH FROM {$dateExpression}) = ?";
            $binds[] = $filters['month'];
        }
        return $sql;
    }

    private function money($value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function monthKey(string $date): string
    {
        return substr($date, 0, 7);
    }

    private function monthLabel(string $monthKey, ?int $selectedYear): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $monthKey);
        if (!$date) {
            return $monthKey;
        }
        return $date->format($selectedYear !== null ? 'F' : 'F Y');
    }

    /**
     * GET /api/admin/financial-reports?year=2026&month=11
     */
    public function index(): ResponseInterface
    {
        $denied = $this->authorizeAdmin();
        if ($denied) {
            return $denied;
        }

        try {
            $filters = $this->readFilters();
            $report = $this->buildReport($filters);
            return $this->respondJson($report);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            log_message('error', 'Admin financial report failed: {message}', [
                'message' => $e->getMessage(),
            ]);
            return $this->fail('Unable to generate the financial report. Check the server log.', 500);
        }
    }

    private function buildReport(array $filters): array
    {
        $salesBinds = self::SALES_STATUSES;
        $salesDateFilter = $this->dateFilterSql('si.invoice_date', $filters, $salesBinds);

        $salesRows = $this->db->query(
            "SELECT si.id, si.invoice_number, si.customer_name_snapshot,
                    si.invoice_date::text AS invoice_date,
                    si.status, si.total_price_incl_vat, si.bank,
                    si.payment_confirmed_at
             FROM sales_invoices si
             WHERE si.status IN (?, ?, ?){$salesDateFilter}
             ORDER BY si.invoice_date DESC, si.created_at DESC",
            $salesBinds
        )->getResultArray();

        $totalSales = 0.0;
        $monthly = [];
        $salesInvoiceIds = [];

        foreach ($salesRows as $row) {
            $date = (string) $row['invoice_date'];
            $key = $this->monthKey($date);
            $amount = $this->money($row['total_price_incl_vat']);
            $totalSales += $amount;
            $salesInvoiceIds[] = $row['id'];
            if (!isset($monthly[$key])) {
                $monthly[$key] = $this->emptyMonth($key, $filters['year']);
            }
            $monthly[$key]['totalSales'] += $amount;
        }

        // Royalty is calculated from approved invoice lines, using the shared
        // 3% VAT-exclusive line-value rule (not withholding and not invoice VAT).
        $royaltyBinds = self::ROYALTY_STATUSES;
        $royaltyDate = 'COALESCE(sil.order_date, si.invoice_date)';
        $royaltyDateFilter = $this->dateFilterSql($royaltyDate, $filters, $royaltyBinds);
        $royaltyRows = $this->db->query(
            "SELECT {$royaltyDate}::text AS royalty_date,
                    COALESCE(SUM(sil.line_total_excl_vat * 0.03), 0) AS royalty
             FROM sales_invoice_lines sil
             INNER JOIN sales_invoices si ON si.id = sil.invoice_id
             WHERE si.status IN (?, ?){$royaltyDateFilter}
             GROUP BY {$royaltyDate}",
            $royaltyBinds
        )->getResultArray();

        $totalRoyalty = 0.0;
        foreach ($royaltyRows as $row) {
            $key = $this->monthKey((string) $row['royalty_date']);
            $amount = $this->money($row['royalty']);
            $totalRoyalty += $amount;
            if (!isset($monthly[$key])) {
                $monthly[$key] = $this->emptyMonth($key, $filters['year']);
            }
            $monthly[$key]['royalty'] += $amount;
        }

        // Count expenses once according to the shared ownership rule:
        // FINANCE/LEGACY are counted; ADMIN is counted only after approval.
        $expenseBinds = [];
        $expenseDateFilter = $this->dateFilterSql('e.expense_date', $filters, $expenseBinds);
        $expenseRows = $this->db->query(
            "SELECT e.expense_date::text AS expense_date,
                    COALESCE(e.total_amount, e.amount_excl_vat + COALESCE(e.vat_amount, 0), e.amount_excl_vat, 0) AS expense_amount
             FROM expenses e
             WHERE (
                 COALESCE(e.expense_source, 'LEGACY') <> 'ADMIN'
                 OR UPPER(COALESCE(e.review_status, e.status, '')) = 'APPROVED'
             ){$expenseDateFilter}
             ORDER BY e.expense_date ASC",
            $expenseBinds
        )->getResultArray();

        $totalExpenses = 0.0;
        foreach ($expenseRows as $row) {
            $key = $this->monthKey((string) $row['expense_date']);
            $amount = $this->money($row['expense_amount']);
            $totalExpenses += $amount;
            if (!isset($monthly[$key])) {
                $monthly[$key] = $this->emptyMonth($key, $filters['year']);
            }
            $monthly[$key]['expenses'] += $amount;
        }

        $vat = $this->money($totalSales - ($totalSales / 1.15));
        $grossIncome = $this->money($totalSales - $vat);
        $netProfit = $this->money($grossIncome - $totalExpenses - $totalRoyalty);

        // Apply the same centralized formulas independently to each month.
        foreach ($monthly as &$monthRow) {
            $monthRow['totalSales'] = $this->money($monthRow['totalSales']);
            $monthRow['vat'] = $this->money(
                $monthRow['totalSales'] - ($monthRow['totalSales'] / 1.15)
            );
            $monthRow['grossIncome'] = $this->money($monthRow['totalSales'] - $monthRow['vat']);
            $monthRow['expenses'] = $this->money($monthRow['expenses']);
            $monthRow['royalty'] = $this->money($monthRow['royalty']);
            $monthRow['netProfit'] = $this->money(
                $monthRow['grossIncome'] - $monthRow['expenses'] - $monthRow['royalty']
            );
        }
        unset($monthRow);

        // Posted sales financial records shown in the page's Financial Records table.
        $recordBinds = [];
        $recordDateFilter = $this->dateFilterSql('fr.record_date', $filters, $recordBinds);
        $recordRows = $this->db->query(
            "SELECT fr.id, fr.record_number,
                    COALESCE(si.customer_name_snapshot, '') AS customer_name,
                    COALESCE(si.total_price_incl_vat, fr.amount, 0) AS total_price,
                    COALESCE(si.bank, '') AS bank,
                    fr.record_date::text AS posted_at
             FROM financial_records fr
             LEFT JOIN sales_invoices si
               ON fr.source_type = 'SALES_INVOICE' AND si.id = fr.source_id
             WHERE fr.source_type = 'SALES_INVOICE'
               AND fr.status = 'POSTED'{$recordDateFilter}
             ORDER BY fr.record_date DESC, fr.created_at DESC",
            $recordBinds
        )->getResultArray();

        $records = array_map(static function (array $row): array {
            return [
                'id' => (string) $row['id'],
                'recordNumber' => (string) $row['record_number'],
                'customerName' => (string) $row['customer_name'],
                'totalPrice' => (float) $row['total_price'],
                'bank' => (string) $row['bank'],
                'postedAt' => (string) $row['posted_at'],
            ];
        }, $recordRows);

        // Payroll records are displayed separately and are not added to the
        // expense KPI here; the shared Net Profit formula deducts counted
        // operational expenses and royalty only.
        $payrollBinds = [];
        $payrollDate = 'COALESCE(pr.paid_at::date, pfr.record_date)';
        $payrollDateFilter = $this->dateFilterSql($payrollDate, $filters, $payrollBinds);
        $payrollRows = $this->db->query(
            "SELECT pfr.id, pfr.record_number, pr.period_label,
                    pfr.total_amount, COALESCE(pr.paid_at::text, pfr.record_date::text) AS paid_at
             FROM payroll_financial_records pfr
             INNER JOIN payroll_runs pr ON pr.id = pfr.payroll_run_id
             WHERE 1=1{$payrollDateFilter}
             ORDER BY {$payrollDate} DESC, pfr.created_at DESC",
            $payrollBinds
        )->getResultArray();

        $payrollRecords = array_map(static function (array $row): array {
            return [
                'id' => (string) $row['id'],
                'recordNumber' => (string) $row['record_number'],
                'payrollPeriod' => (string) $row['period_label'],
                'totalNet' => (float) $row['total_amount'],
                'paidAt' => (string) $row['paid_at'],
            ];
        }, $payrollRows);

        ksort($monthly);

        return [
            'filters' => [
                'year' => $filters['year'],
                'month' => $filters['month'],
            ],
            'totals' => [
                'totalSales' => $this->money($totalSales),
                'vat' => $vat,
                'grossIncome' => $grossIncome,
                'totalExpenses' => $this->money($totalExpenses),
                'royalty' => $this->money($totalRoyalty),
                'netProfit' => $netProfit,
            ],
            'byMonth' => array_values($monthly),
            'records' => $records,
            'payrollRecords' => $payrollRecords,
        ];
    }

    private function emptyMonth(string $monthKey, ?int $selectedYear): array
    {
        return [
            'monthKey' => $monthKey,
            'month' => $this->monthLabel($monthKey, $selectedYear),
            'totalSales' => 0.0,
            'vat' => 0.0,
            'grossIncome' => 0.0,
            'expenses' => 0.0,
            'royalty' => 0.0,
            'netProfit' => 0.0,
        ];
    }
}
