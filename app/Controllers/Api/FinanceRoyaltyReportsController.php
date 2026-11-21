<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class FinanceRoyaltyReportsController extends ResourceController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    private const ROYALTY_RATE = 0.03;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Authenticate the current user.
     */
    private function authenticate(): ?ResponseInterface
    {
        $header = trim($this->request->getHeaderLine('Authorization'));

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->failUnauthorized(
                'A valid bearer token is required.'
            );
        }

        $tokenHash = hash('sha256', trim($matches[1]));

        $session = $this->db->table('user_sessions')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if (!$session) {
            return $this->failUnauthorized(
                'Your session is invalid or expired.'
            );
        }

        $user = $this->db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (
            !$user ||
            strtolower(trim((string) ($user['account_status'] ?? ''))) !== 'active'
        ) {
            return $this->failUnauthorized(
                'This account is unavailable.'
            );
        }

        $this->authenticatedUser = $user;

        $this->db->table('user_sessions')
            ->where('id', $session['id'])
            ->update([
                'last_used_at' => date('Y-m-d H:i:s'),
            ]);

        return null;
    }

    /**
     * Round monetary values to two decimal places.
     */
    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    /**
     * Convert a database date/timestamp to milliseconds.
     */
    private function milliseconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false
            ? null
            : $timestamp * 1000;
    }

    /**
     * Return eligible royalty rows that have not already been
     * included in a completed royalty batch.
     *
     * PostgreSQL automatically creates completed batches.
     */
    private function eligibleRows(): array
    {
        $sql = "
            SELECT
                CASE
                    WHEN l.contract_order_id IS NOT NULL
                        THEN 'CONTRACT_ORDER'::text
                    ELSE 'INVOICE'::text
                END AS source,

                (i.id::text || '-' || l.id::text) AS row_key,

                i.id::text AS invoice_id,
                l.id::text AS invoice_line_id,
                l.contract_order_id::text AS contract_order_id,

                i.invoice_number::text AS invoice_number,
                c.contract_number::text AS contract_number,

                l.order_number::integer AS order_number,
                l.royalty_order_number::integer AS royalty_order_number,

                l.royalty_invoice_number::text AS royalty_invoice_number,
                l.aggregate::text AS aggregate,

                l.quantity::numeric AS quantity,
                l.unit_price::numeric AS unit_price,
                l.line_total_excl_vat::numeric AS line_total_excl_vat,

                COALESCE(l.order_date, i.invoice_date)::date AS source_date,

                i.created_at AS sort_date

            FROM sales_invoices i

            INNER JOIN sales_invoice_lines l
                ON l.invoice_id = i.id

            LEFT JOIN contracts c
                ON c.id = i.contract_id

            WHERE i.status IN (
                'FINANCE_APPROVED',
                'PAYMENT_CONFIRMED'
            )

            AND NOT EXISTS (
                SELECT 1
                FROM royalty_batch_items rbi

                INNER JOIN royalty_batches rb
                    ON rb.id = rbi.batch_id

                WHERE UPPER(rb.status) IN ('FINAL', 'FINALIZED')

                  AND (
                      rbi.invoice_line_id = l.id

                      OR (
                          l.contract_order_id IS NOT NULL
                          AND rbi.contract_order_id = l.contract_order_id
                      )
                  )
            )

            ORDER BY
                i.created_at ASC,
                i.id ASC,
                l.order_number ASC
        ";

        $rawRows = $this->db->query($sql)->getResultArray();

        $rows = [];

        foreach ($rawRows as $row) {
            $lineTotal = $this->money(
                $row['line_total_excl_vat']
            );

            $contractOrderId = $row['contract_order_id'] !== null
                ? (string) $row['contract_order_id']
                : null;

            $rows[] = [
                'key' => (string) $row['row_key'],

                'source' => (string) $row['source'],

                'date' => $this->milliseconds(
                    $row['source_date']
                ),

                'royaltyInvoiceNumber' => $row['royalty_invoice_number'],

                'aggregate' => (string) $row['aggregate'],

                'quantity' => (float) $row['quantity'],

                'unitPrice' => (float) $row['unit_price'],

                'lineTotalExclVat' => $lineTotal,

                'royalty' => $this->money(
                    max(0, $lineTotal) * self::ROYALTY_RATE
                ),

                'invoiceId' => (string) $row['invoice_id'],

                'invoiceNumber' => (string) $row['invoice_number'],

                'contractNumber' => $row['contract_number'],

                'contractOrderId' => $contractOrderId,

                'contractOrderNumber' => $contractOrderId !== null
                    ? (string) $row['royalty_order_number']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * GET /api/finance/royalty-reports
     *
     * Return currently eligible royalty report rows.
     */
    public function index()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        try {
            $rows = $this->eligibleRows();

            return $this->respond([
                'success' => true,
                'data' => $rows,
            ]);

        } catch (Throwable $e) {
            log_message(
                'error',
                'Finance royalty report failed: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Unable to load royalty report rows.'
            );
        }
    }

    /**
     * Return the keys belonging to a completed batch.
     */
    private function batchRowKeys(string $batchId): array
    {
        $sql = "
            SELECT
                CASE
                    WHEN rbi.invoice_line_id IS NOT NULL THEN
                        l.invoice_id::text || '-' || rbi.invoice_line_id::text

                    WHEN rbi.contract_order_id IS NOT NULL THEN
                        'contract-order:' || rbi.contract_order_id::text

                    ELSE NULL
                END AS row_key

            FROM royalty_batch_items rbi

            LEFT JOIN sales_invoice_lines l
                ON l.id = rbi.invoice_line_id

            WHERE rbi.batch_id = ?

            ORDER BY rbi.created_at ASC, rbi.id ASC
        ";

        $records = $this->db->query(
            $sql,
            [$batchId]
        )->getResultArray();

        $keys = [];

        foreach ($records as $record) {
            if (!empty($record['row_key'])) {
                $keys[] = (string) $record['row_key'];
            }
        }

        return $keys;
    }

    /**
     * GET /api/finance/royalty-reports/batches
     *
     * Return completed automatic royalty batches.
     */
    public function batches()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        try {
            $sql = "
                SELECT
                    rb.id::text AS id,

                    rb.batch_number,
                    rb.status,
                    rb.total_amount,
                    rb.finalized_at,

                    COUNT(rbi.id)::integer AS record_count,

                    COALESCE(
                        SUM(rbi.quantity),
                        0
                    ) AS total_quantity,

                    COALESCE(
                        SUM(rbi.line_total_excl_vat),
                        0
                    ) AS total_sales

                FROM royalty_batches rb

                LEFT JOIN royalty_batch_items rbi
                    ON rbi.batch_id = rb.id

                WHERE UPPER(rb.status) IN ('FINAL', 'FINALIZED')

                GROUP BY
                    rb.id,
                    rb.batch_number,
                    rb.status,
                    rb.total_amount,
                    rb.finalized_at

                ORDER BY
                    rb.finalized_at DESC,
                    rb.batch_number DESC
            ";

            $records = $this->db->query($sql)->getResultArray();

            $data = [];

            foreach ($records as $row) {
                $data[] = [
                    'id' => (string) $row['id'],

                    'batchNumber' => (int) $row['batch_number'],

                    'orderIds' => $this->batchRowKeys(
                        (string) $row['id']
                    ),

                    'recordCount' => (int) $row['record_count'],

                    'totalQuantity' => (float) $row['total_quantity'],

                    'totalSales' => $this->money(
                        $row['total_sales']
                    ),

                    'totalRoyalty' => $this->money(
                        $row['total_amount']
                    ),

                    'createdAt' => $this->milliseconds(
                        $row['finalized_at']
                    ),

                    'exportedAt' => null,

                    'status' => 'FINALIZED',
                ];
            }

            return $this->respond([
                'success' => true,
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            log_message(
                'error',
                'Finance royalty batches failed: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Unable to load royalty batch history.'
            );
        }
    }

    /**
     * GET /api/finance/royalty-reports/batches/{batchId}
     *
     * Return one completed batch and its persisted records.
     */
    public function showBatch(string $batchId)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        try {
            $sql = "
                SELECT
                    rb.id::text AS id,
                    rb.batch_number,
                    rb.status,
                    rb.total_amount,
                    rb.finalized_at,

                    COUNT(rbi.id)::integer AS record_count,

                    COALESCE(
                        SUM(rbi.quantity),
                        0
                    ) AS total_quantity,

                    COALESCE(
                        SUM(rbi.line_total_excl_vat),
                        0
                    ) AS total_sales

                FROM royalty_batches rb

                LEFT JOIN royalty_batch_items rbi
                    ON rbi.batch_id = rb.id

                WHERE rb.id = ?

                  AND UPPER(rb.status) IN ('FINAL', 'FINALIZED')

                GROUP BY
                    rb.id,
                    rb.batch_number,
                    rb.status,
                    rb.total_amount,
                    rb.finalized_at
            ";

            $record = $this->db->query(
                $sql,
                [$batchId]
            )->getRowArray();

            if (!$record) {
                return $this->failNotFound(
                    'The requested completed royalty batch was not found.'
                );
            }

            $rows = $this->batchItems($batchId);

            $batch = [
                'id' => (string) $record['id'],

                'batchNumber' => (int) $record['batch_number'],

                'orderIds' => $this->batchRowKeys($batchId),

                'recordCount' => (int) $record['record_count'],

                'totalQuantity' => (float) $record['total_quantity'],

                'totalSales' => $this->money(
                    $record['total_sales']
                ),

                'totalRoyalty' => $this->money(
                    $record['total_amount']
                ),

                'createdAt' => $this->milliseconds(
                    $record['finalized_at']
                ),

                'exportedAt' => null,

                'status' => 'FINALIZED',
            ];

            return $this->respond([
                'success' => true,
                'data' => [
                    'batch' => $batch,
                    'rows' => $rows,
                ],
            ]);

        } catch (Throwable $e) {
            log_message(
                'error',
                'Finance royalty batch details failed: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Unable to load the selected royalty batch.'
            );
        }
    }

    /**
     * Return saved royalty records belonging to a completed batch.
     */
    private function batchItems(string $batchId): array
    {
        $sql = "
            SELECT
                rbi.id::text AS id,
                rbi.batch_id::text AS batch_id,

                rbi.invoice_line_id::text AS invoice_line_id,
                rbi.contract_order_id::text AS contract_order_id,

                rbi.source_order_number,
                rbi.royalty_invoice_number,

                rbi.aggregate,
                rbi.quantity,
                rbi.unit_price,
                rbi.line_total_excl_vat,

                rbi.royalty_rate,
                rbi.royalty_amount,
                rbi.source_order_date,

                i.id::text AS invoice_id,
                i.invoice_number,

                c.contract_number

            FROM royalty_batch_items rbi

            LEFT JOIN sales_invoice_lines l
                ON l.id = rbi.invoice_line_id

            LEFT JOIN sales_invoices i
                ON i.id = l.invoice_id

            LEFT JOIN contracts c
                ON c.id = i.contract_id

            WHERE rbi.batch_id = ?

            ORDER BY rbi.created_at ASC, rbi.id ASC
        ";

        $records = $this->db->query(
            $sql,
            [$batchId]
        )->getResultArray();

        $data = [];

        foreach ($records as $row) {
            $data[] = [
                'id' => (string) $row['id'],

                'batchId' => (string) $row['batch_id'],

                'key' => !empty($row['invoice_line_id'])
                    && !empty($row['invoice_id'])
                    ? $row['invoice_id'] . '-' . $row['invoice_line_id']
                    : (
                        !empty($row['contract_order_id'])
                            ? 'contract-order:' . $row['contract_order_id']
                            : null
                    ),

                'source' => !empty($row['contract_order_id'])
                    ? 'CONTRACT_ORDER'
                    : 'INVOICE',

                'invoiceId' => $row['invoice_id'],

                'invoiceNumber' => $row['invoice_number'],

                'contractNumber' => $row['contract_number'],

                'contractOrderId' => $row['contract_order_id'],

                'sourceOrderNumber' => (int) $row['source_order_number'],

                'royaltyInvoiceNumber' => $row['royalty_invoice_number'],

                'aggregate' => (string) $row['aggregate'],

                'quantity' => (float) $row['quantity'],

                'unitPrice' => (float) $row['unit_price'],

                'lineTotalExclVat' => $this->money(
                    $row['line_total_excl_vat']
                ),

                'royaltyRate' => (float) $row['royalty_rate'],

                'royaltyAmount' => $this->money(
                    $row['royalty_amount']
                ),

                'royalty' => $this->money(
                    $row['royalty_amount']
                ),

                'date' => $this->milliseconds(
                    $row['source_order_date']
                ),
            ];
        }

        return $data;
    }

    /**
     * Manual batch finalization is disabled.
     *
     * PostgreSQL automatically creates batches when 50 eligible
     * pending invoice lines are available.
     */
    public function finalizeBatch()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        return $this->fail(
            'Manual royalty batch finalization is disabled. PostgreSQL automatically creates completed batches when 50 eligible records are available.',
            409
        );
    }
}
