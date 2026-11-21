<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use DateTime;
use DateInterval;
use DatePeriod;
use Throwable;

class FinanceSummaryReportController extends BaseController
{
    /**
     * GET /api/finance/summary-report?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     *
     * Returns a daily consolidated sales and expenses report.
     */
    public function index(): ResponseInterface
    {
        $startDate = trim((string) $this->request->getGet('start_date'));
        $endDate   = trim((string) $this->request->getGet('end_date'));

        // Validate the requested dates.
        $start = DateTime::createFromFormat('!Y-m-d', $startDate);
        $end   = DateTime::createFromFormat('!Y-m-d', $endDate);

        if (
            !$start ||
            !$end ||
            $start->format('Y-m-d') !== $startDate ||
            $end->format('Y-m-d') !== $endDate
        ) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Valid start_date and end_date are required in YYYY-MM-DD format.',
                ]);
        }

        if ($start > $end) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'The start date cannot be later than the end date.',
                ]);
        }

        try {
            $db = db_connect();

            /*
             * 1. Get every aggregate type.
             *
             * sales_invoice_lines.aggregate is TEXT, so it is matched
             * against aggregate_types.name.
             */
            $aggregateTypes = $db->query(
                "SELECT id, name
                 FROM aggregate_types
                 ORDER BY name ASC"
            )->getResultArray();

            /*
             * 2. Get confirmed sales grouped by invoice date and
             *    aggregate type.
             *
             * PAYMENT_CONFIRMED is the final qualifying invoice status.
             *
             * Amount displayed in the report includes 15% VAT.
             */
            $salesRows = $db->query(
                "
                SELECT
                    si.invoice_date::text AS report_date,
                    COALESCE(at.name, sil.aggregate) AS aggregate_type,
                    SUM(sil.quantity) AS quantity,
                    SUM(sil.line_total_excl_vat) AS amount_excl_vat,
                    SUM(sil.line_total_excl_vat * si.vat_rate) AS vat_amount,
                    SUM(
                        sil.line_total_excl_vat
                        + (sil.line_total_excl_vat * si.vat_rate)
                    ) AS total_amount

                FROM sales_invoice_lines sil

                INNER JOIN sales_invoices si
                    ON si.id = sil.invoice_id

                LEFT JOIN aggregate_types at
                    ON LOWER(TRIM(at.name)) = LOWER(TRIM(sil.aggregate))

                WHERE si.status = 'PAYMENT_CONFIRMED'
                  AND si.invoice_date >= ?
                  AND si.invoice_date <= ?

                GROUP BY
                    si.invoice_date,
                    COALESCE(at.name, sil.aggregate)

                ORDER BY
                    si.invoice_date,
                    COALESCE(at.name, sil.aggregate)
                ",
                [$startDate, $endDate]
            )->getResultArray();

            /*
             * 3. Get approved expenses.
             *
             * Each expense remains an individual child row.
             */
            $expenseRows = $db->query(
                "
                SELECT
                    expense_date::text AS report_date,
                    expense_type,
                    total_amount,
                    remark

                FROM expenses

                WHERE UPPER(TRIM(status)) = 'APPROVED'
                  AND expense_date >= ?
                  AND expense_date <= ?

                ORDER BY
                    expense_date ASC,
                    expense_type ASC,
                    expense_number ASC
                ",
                [$startDate, $endDate]
            )->getResultArray();

            /*
             * 4. Initialize every date in the selected period.
             *
             * This ensures that dates with no transactions are
             * still included in the report.
             */
            $days = [];

            $period = new DatePeriod(
                $start,
                new DateInterval('P1D'),
                (clone $end)->modify('+1 day')
            );

            foreach ($period as $date) {
                $dateKey = $date->format('Y-m-d');

                $dailySales = [];

                foreach ($aggregateTypes as $aggregateType) {
                    $dailySales[$aggregateType['name']] = [
                        'aggregate_type' => $aggregateType['name'],
                        'quantity'       => 0.0,
                        'amount'         => 0.0,
                        'amount_excl_vat'=> 0.0,
                        'vat_amount'     => 0.0,
                    ];
                }

                $days[$dateKey] = [
                    'date' => $dateKey,

                    'sales' => $dailySales,

                    'expenses' => [],

                    'totals' => [
                        'sales_quantity'       => 0.0,
                        'sales_amount_excl_vat'=> 0.0,
                        'sales_vat'            => 0.0,
                        'sales_amount'         => 0.0,
                        'expense_amount'       => 0.0,
                    ],
                ];
            }

            /*
             * 5. Add sales to their corresponding days.
             *
             * Unknown aggregate names are also retained so that
             * an existing sale is never silently discarded.
             */
            foreach ($salesRows as $sale) {
                $dateKey = $sale['report_date'];
                $name    = $sale['aggregate_type'];

                if (!isset($days[$dateKey])) {
                    continue;
                }

                if (!isset($days[$dateKey]['sales'][$name])) {
                    $days[$dateKey]['sales'][$name] = [
                        'aggregate_type' => $name,
                        'quantity'       => 0.0,
                        'amount'         => 0.0,
                        'amount_excl_vat'=> 0.0,
                        'vat_amount'     => 0.0,
                    ];
                }

                $quantity = (float) $sale['quantity'];
                $exclVat  = (float) $sale['amount_excl_vat'];
                $vat      = (float) $sale['vat_amount'];
                $total    = (float) $sale['total_amount'];

                $days[$dateKey]['sales'][$name]['quantity'] += $quantity;
                $days[$dateKey]['sales'][$name]['amount_excl_vat'] += $exclVat;
                $days[$dateKey]['sales'][$name]['vat_amount'] += $vat;
                $days[$dateKey]['sales'][$name]['amount'] += $total;

                $days[$dateKey]['totals']['sales_quantity'] += $quantity;
                $days[$dateKey]['totals']['sales_amount_excl_vat'] += $exclVat;
                $days[$dateKey]['totals']['sales_vat'] += $vat;
                $days[$dateKey]['totals']['sales_amount'] += $total;
            }

            /*
             * 6. Add expenses to their corresponding days.
             */
            foreach ($expenseRows as $expense) {
                $dateKey = $expense['report_date'];

                if (!isset($days[$dateKey])) {
                    continue;
                }

                $amount = (float) $expense['total_amount'];

                $days[$dateKey]['expenses'][] = [
                    'expense_type' => $expense['expense_type'],
                    'amount'       => $amount,
                    'remark'       => $expense['remark'],
                ];

                $days[$dateKey]['totals']['expense_amount'] += $amount;
            }

            /*
             * 7. Calculate daily and period totals.
             */
            $periodTotals = [
                'sales_quantity'        => 0.0,
                'sales_amount_excl_vat' => 0.0,
                'sales_vat'             => 0.0,
                'sales_amount'          => 0.0,
                'expense_amount'        => 0.0,
            ];

            foreach ($days as &$day) {
                // Convert aggregate sales map into a normal array.
                $day['sales'] = array_values($day['sales']);

                foreach ($periodTotals as $key => $value) {
                    $periodTotals[$key] += $day['totals'][$key];
                }

                // Round daily monetary totals.
                $day['totals']['sales_amount_excl_vat'] =
                    round($day['totals']['sales_amount_excl_vat'], 2);

                $day['totals']['sales_vat'] =
                    round($day['totals']['sales_vat'], 2);

                $day['totals']['sales_amount'] =
                    round($day['totals']['sales_amount'], 2);

                $day['totals']['expense_amount'] =
                    round($day['totals']['expense_amount'], 2);
            }

            unset($day);

            $totalSales  = round($periodTotals['sales_amount'], 2);
            $totalVat    = round($periodTotals['sales_vat'], 2);
            $grossIncome = round($totalSales - $totalVat, 2);
            $royalty     = round($grossIncome * 0.03, 2);
            $totalExpense = round($periodTotals['expense_amount'], 2);

            $netProfit = round(
                $grossIncome - $royalty - $totalExpense,
                2
            );

            /*
             * 8. Return the report.
             */
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Summary report generated successfully.',

                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                    ],

                    'aggregate_types' => $aggregateTypes,

                    'days' => array_values($days),

                    'totals' => [
                        'sales_quantity' => round(
                            $periodTotals['sales_quantity'],
                            3
                        ),

                        'sales_amount_excl_vat' => round(
                            $periodTotals['sales_amount_excl_vat'],
                            2
                        ),

                        'sales_vat' => $totalVat,

                        'sales_amount' => $totalSales,

                        'expense_amount' => $totalExpense,
                    ],

                    'summary' => [
                        'total_sales'    => $totalSales,
                        'vat_payable'    => $totalVat,
                        'gross_income'   => $grossIncome,
                        'royalty_payable'=> $royalty,
                        'expense'        => $totalExpense,
                        'net_profit'     => $netProfit,
                    ],
                ],
            ]);

        } catch (Throwable $e) {
            log_message(
                'error',
                'Finance Summary Report Error: ' . $e->getMessage()
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to generate the finance summary report.',
                ]);
        }
    }
}
