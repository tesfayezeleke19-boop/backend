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
    private const MAX_BATCH_SIZE = 50;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private function authenticate(): ?ResponseInterface
    {
        $header = trim($this->request->getHeaderLine('Authorization'));

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->failUnauthorized('A valid bearer token is required.');
        }

        $tokenHash = hash('sha256', trim($matches[1]));

        $session = $this->db->table('user_sessions')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if (!$session) {
            return $this->failUnauthorized('Your session is invalid or expired.');
        }

        $user = $this->db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (
            !$user ||
            strtolower(trim((string) ($user['account_status'] ?? ''))) !== 'active'
        ) {
            return $this->failUnauthorized('This account is unavailable.');
        }

        $this->authenticatedUser = $user;

        $this->db->table('user_sessions')
            ->where('id', $session['id'])
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);

        return null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function jsonInput(): array
    {
        $input = $this->request->getJSON(true);
        return is_array($input) ? $input : [];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function milliseconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : $timestamp * 1000;
    }

    /**
     * Returns eligible invoice lines and completed contract orders.
     * Rows that have already been finalized are removed from the report.
     */
    private function eligibleRows(bool $excludeFinalized = true): array
    {
        $sql = "
            SELECT
                'INVOICE'::text AS source,
                (i.id::text || '-' || l.id::text) AS row_key,
                i.id::text AS invoice_id,
                l.id::text AS invoice_line_id,
                NULL::text AS contract_order_id,
                i.invoice_number::text AS invoice_number,
                NULL::text AS contract_number,
                l.order_number::integer AS order_number,
                l.royalty_order_number::integer AS royalty_order_number,
                l.royalty_invoice_number::text AS royalty_invoice_number,
                l.aggregate::text AS aggregate,
                l.quantity::numeric AS quantity,
                l.unit_price::numeric AS unit_price,
                l.line_total_excl_vat::numeric AS line_total_excl_vat,
                i.invoice_date::date AS source_date,
                i.created_at AS sort_date
            FROM sales_invoices i
            INNER JOIN sales_invoice_lines l ON l.invoice_id = i.id
            WHERE i.status IN ('FINANCE_APPROVED', 'PAYMENT_CONFIRMED')
              AND l.contract_order_id IS NULL

            UNION ALL

            SELECT
                'CONTRACT_ORDER'::text AS source,
                ('contract-order:' || co.id::text) AS row_key,
                NULL::text AS invoice_id,
                NULL::text AS invoice_line_id,
                co.id::text AS contract_order_id,
                NULL::text AS invoice_number,
                c.contract_number::text AS contract_number,
                co.order_number::integer AS order_number,
                co.order_number::integer AS royalty_order_number,
                NULL::text AS royalty_invoice_number,
                co.aggregate::text AS aggregate,
                co.quantity::numeric AS quantity,
                co.unit_price::numeric AS unit_price,
                ROUND((co.quantity * co.unit_price)::numeric, 2) AS line_total_excl_vat,
                co.order_date::date AS source_date,
                COALESCE(co.order_date::timestamp, co.created_at) AS sort_date
            FROM contract_orders co
            INNER JOIN contracts c ON c.id = co.contract_id
            WHERE UPPER(co.status) = 'COMPLETED'
        ";

        $rawRows = $this->db->query($sql)->getResultArray();

        $finalized = [];

        if ($excludeFinalized) {
            $items = $this->db->query("
                SELECT
                    rbi.invoice_line_id::text AS invoice_line_id,
                    rbi.contract_order_id::text AS contract_order_id
                FROM royalty_batch_items rbi
                INNER JOIN royalty_batches rb ON rb.id = rbi.batch_id
                WHERE UPPER(rb.status) IN ('FINAL', 'FINALIZED')
            ")->getResultArray();

            foreach ($items as $item) {
                if (!empty($item['invoice_line_id'])) {
                    $finalized['invoice-line:' . $item['invoice_line_id']] = true;
                }
                if (!empty($item['contract_order_id'])) {
                    $finalized['contract-order:' . $item['contract_order_id']] = true;
                }
            }
        }

        $rows = [];

        foreach ($rawRows as $row) {
            $source = (string) $row['source'];
            $rowKey = (string) $row['row_key'];

            $finalizedKey = $source === 'INVOICE'
                ? 'invoice-line:' . $row['invoice_line_id']
                : 'contract-order:' . $row['contract_order_id'];

            if ($excludeFinalized && isset($finalized[$finalizedKey])) {
                continue;
            }

            $quantity = (float) $row['quantity'];
            $unitPrice = (float) $row['unit_price'];
            $lineTotal = $this->money($row['line_total_excl_vat']);

            $rows[] = [
                'key' => $rowKey,
                'source' => $source,
                'date' => $this->milliseconds($row['source_date']),
                'royaltyInvoiceNumber' => $row['royalty_invoice_number'],
                'aggregate' => (string) $row['aggregate'],
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'lineTotalExclVat' => $lineTotal,
                'royalty' => $this->money(max(0, $lineTotal) * self::ROYALTY_RATE),
                'invoiceId' => $row['invoice_id'],
                'invoiceNumber' => $row['invoice_number'],
                'contractNumber' => $row['contract_number'],
                'contractOrderId' => $row['contract_order_id'],
                'contractOrderNumber' => $source === 'CONTRACT_ORDER'
                    ? (string) $row['order_number']
                    : null,
                '_invoiceLineId' => $row['invoice_line_id'],
                '_contractOrderId' => $row['contract_order_id'],
                '_orderNumber' => (int) $row['order_number'],
                '_sourceDate' => $row['source_date'],
                '_sortDate' => $row['sort_date'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp(
                (string) ($a['_sortDate'] ?? ''),
                (string) ($b['_sortDate'] ?? '')
            );

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp($a['key'], $b['key']);
        });

        return $rows;
    }

    private function publicRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset(
                $row['_invoiceLineId'],
                $row['_contractOrderId'],
                $row['_orderNumber'],
                $row['_sourceDate'],
                $row['_sortDate']
            );
            return $row;
        }, $rows);
    }

    public function index()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        try {
            $rows = $this->eligibleRows(true);

            return $this->respond([
                'success' => true,
                'data' => $this->publicRows($rows),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Finance royalty report failed: ' . $e->getMessage());
            return $this->failServerError('Unable to load royalty report rows.');
        }
    }

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
                    COALESCE(SUM(rbi.quantity), 0) AS total_quantity,
                    COALESCE(SUM(rbi.line_total_excl_vat), 0) AS total_sales
                FROM royalty_batches rb
                LEFT JOIN royalty_batch_items rbi ON rbi.batch_id = rb.id
                GROUP BY rb.id, rb.batch_number, rb.status, rb.total_amount, rb.finalized_at
                ORDER BY rb.finalized_at DESC, rb.batch_number DESC
            ";

            $records = $this->db->query($sql)->getResultArray();
            $data = [];

            foreach ($records as $row) {
                $data[] = [
                    'id' => (string) $row['id'],
                    'batchNumber' => (int) $row['batch_number'],
                    'orderIds' => $this->batchRowKeys((string) $row['id']),
                    'recordCount' => (int) $row['record_count'],
                    'totalQuantity' => (float) $row['total_quantity'],
                    'totalSales' => $this->money($row['total_sales']),
                    'totalRoyalty' => $this->money($row['total_amount']),
                    'createdAt' => $this->milliseconds($row['finalized_at']),
                    'exportedAt' => null,
                    'status' => in_array(
                        strtoupper((string) $row['status']),
                        ['FINAL', 'FINALIZED'],
                        true
                    ) ? 'FINALIZED' : strtoupper((string) $row['status']),
                ];
            }

            return $this->respond([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Finance royalty batches failed: ' . $e->getMessage());
            return $this->failServerError('Unable to load royalty batch history.');
        }
    }

    private function batchRowKeys(string $batchId): array
    {
        $items = $this->db->table('royalty_batch_items')
            ->select('invoice_line_id, contract_order_id')
            ->where('batch_id', $batchId)
            ->get()
            ->getResultArray();

        $keys = [];

        foreach ($items as $item) {
            if (!empty($item['invoice_line_id'])) {
                $line = $this->db->table('sales_invoice_lines')
                    ->select('invoice_id')
                    ->where('id', $item['invoice_line_id'])
                    ->get()
                    ->getRowArray();

                if ($line) {
                    $keys[] = $line['invoice_id'] . '-' . $item['invoice_line_id'];
                }
            } elseif (!empty($item['contract_order_id'])) {
                $keys[] = 'contract-order:' . $item['contract_order_id'];
            }
        }

        return $keys;
    }

    public function finalizeBatch()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $input = $this->jsonInput();
        $keys = $input['orderIds'] ?? $input['keys'] ?? null;

        if (!is_array($keys) || count($keys) < 1 || count($keys) > self::MAX_BATCH_SIZE) {
            return $this->failValidationErrors(
                'Provide between 1 and 50 royalty row keys.'
            );
        }

        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn ($key) => is_string($key) && trim($key) !== ''
        )));

        if (count($keys) < 1 || count($keys) > self::MAX_BATCH_SIZE) {
            return $this->failValidationErrors('The royalty row keys are invalid.');
        }

        $this->db->transBegin();

        try {
            // Serialize batch finalization to avoid concurrent duplicate batches.
            $this->db->query('SELECT pg_advisory_xact_lock(734282)');

            $eligible = $this->eligibleRows(true);
            $byKey = [];

            foreach ($eligible as $row) {
                $byKey[$row['key']] = $row;
            }

            foreach ($keys as $key) {
                if (!isset($byKey[$key])) {
                    throw new \DomainException(
                        'One or more selected rows are no longer eligible. Refresh the report and try again.'
                    );
                }
            }

            $selected = array_map(static fn ($key) => $byKey[$key], $keys);

            $totalQuantity = 0.0;
            $totalSales = 0.0;
            $totalRoyalty = 0.0;

            foreach ($selected as $row) {
                $totalQuantity += (float) $row['quantity'];
                $totalSales += (float) $row['lineTotalExclVat'];
                $totalRoyalty += (float) $row['royalty'];
            }

            $totalRoyalty = $this->money($totalRoyalty);

            $last = $this->db->query("
                SELECT COALESCE(MAX(NULLIF(batch_number, '')::integer), 0) AS last_number
                FROM royalty_batches
                WHERE batch_number ~ '^[0-9]+$'
            ")->getRowArray();

            $batchNumber = (int) ($last['last_number'] ?? 0) + 1;
            $batchId = $this->uuid();

            $this->db->table('royalty_batches')->insert([
                'id' => $batchId,
                'batch_number' => (string) $batchNumber,
                'status' => 'FINAL',
                'total_amount' => $totalRoyalty,
                'finalized_by' => $this->authenticatedUser['id'] ?? null,
                'finalized_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($selected as $row) {
                $this->db->table('royalty_batch_items')->insert([
                    'id' => $this->uuid(),
                    'batch_id' => $batchId,
                    'invoice_line_id' => $row['_invoiceLineId'],
                    'contract_order_id' => $row['_contractOrderId'],
                    'source_order_number' => $row['_orderNumber'],
                    'royalty_invoice_number' => $row['royaltyInvoiceNumber'],
                    'aggregate' => $row['aggregate'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unitPrice'],
                    'line_total_excl_vat' => $row['lineTotalExclVat'],
                    'royalty_rate' => self::ROYALTY_RATE,
                    'royalty_amount' => $row['royalty'],
                    'source_order_date' => $row['_sourceDate'],
                ]);
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }

            $this->db->transCommit();

            return $this->respondCreated([
                'success' => true,
                'message' => 'Royalty batch finalized.',
                'data' => [
                    'id' => $batchId,
                    'batchNumber' => $batchNumber,
                    'orderIds' => $keys,
                    'recordCount' => count($selected),
                    'totalQuantity' => $totalQuantity,
                    'totalSales' => $this->money($totalSales),
                    'totalRoyalty' => $totalRoyalty,
                    'createdAt' => (int) (microtime(true) * 1000),
                    'status' => 'FINALIZED',
                ],
            ]);
        } catch (\DomainException $e) {
            $this->db->transRollback();
            return $this->fail($e->getMessage(), 409);
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Finance royalty batch finalization failed: ' . $e->getMessage());
            return $this->failServerError('Unable to finalize the royalty batch.');
        }
    }
}
