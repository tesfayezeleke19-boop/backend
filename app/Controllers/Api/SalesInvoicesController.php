<?php

namespace App\Controllers\Api;


use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class SalesInvoicesController extends ResourceController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /* -------------------------------------------------------------
     * Authentication
     * ----------------------------------------------------------- */

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
            strtolower(trim((string) ($user['account_status'] ?? '')))
                !== 'active'
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

    private function actorId(): ?string
    {
        return $this->authenticatedUser['id'] ?? null;
    }

    private function jsonInput(): array
    {
        $data = $this->request->getJSON(true);

        return is_array($data) ? $data : [];
    }

    /* -------------------------------------------------------------
     * Utilities
     * ----------------------------------------------------------- */

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

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 't', 'true', 'yes', 'on'],
            true
        );
    }

    
private function toDate(mixed $value): string
{
    if ($value === null || $value === '') {
        return (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Africa/Addis_Ababa')
        ))->format('Y-m-d');
    }

    // Date-only values must remain unchanged.
    // Do not parse them as timestamps.
    if (
        is_string($value) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
    ) {
        return trim($value);
    }

    if (is_numeric($value)) {
        $timestamp = (int) $value;

        // JavaScript timestamps are in milliseconds.
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        // Interpret the timestamp using Ethiopia's timezone,
        // not the server's default timezone.
        return (new \DateTimeImmutable(
            '@' . $timestamp
        ))
            ->setTimezone(new \DateTimeZone('Africa/Addis_Ababa'))
            ->format('Y-m-d');
    }

    try {
        return (new \DateTimeImmutable(
            (string) $value,
            new \DateTimeZone('Africa/Addis_Ababa')
        ))
            ->setTimezone(new \DateTimeZone('Africa/Addis_Ababa'))
            ->format('Y-m-d');
    } catch (\Throwable $e) {
        throw new \InvalidArgumentException(
            'The supplied date is invalid.'
        );
    }
}


    private function toMilliseconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;

            return $number > 9999999999
                ? $number
                : $number * 1000;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp * 1000;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function validUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    /**
     * Only use an evidence ID that refers to an existing attachment.
     * Actual upload and attachment metadata mapping are separate tasks.
     */
    private function validAttachmentId(mixed $value): ?string
    {
        if (!$this->validUuid($value)) {
            return null;
        }

        $exists = $this->db->table('attachments')
            ->where('id', $value)
            ->countAllResults();

        return $exists > 0 ? $value : null;
    }

    /* -------------------------------------------------------------
     * Invoice numbering
     * ----------------------------------------------------------- */

    private function nextInvoiceNumber(): string
    {
        /*
         * Called from the create transaction. PostgreSQL transaction-level
         * advisory locking serializes invoice number allocation.
         */
        $this->db->query('SELECT pg_advisory_xact_lock(734281)');

        $row = $this->db->query(
            "SELECT COALESCE(
                MAX(CAST(SUBSTRING(invoice_number FROM 5) AS BIGINT)),
                0
            ) AS last_number
            FROM sales_invoices
            WHERE invoice_number ~ '^INV-[0-9]+$'"
        )->getRowArray();

        $next = ((int) ($row['last_number'] ?? 0)) + 1;

        return 'INV-' . str_pad(
            (string) $next,
            5,
            '0',
            STR_PAD_LEFT
        );
    }

    /* -------------------------------------------------------------
     * Financial calculations
     * ----------------------------------------------------------- */

    
private function calculate(
    array $orders,
    bool $withholdingEnabled
): array {
    $subtotal = 0.0;
    $normalizedOrders = [];

    foreach (array_values($orders) as $index => $order) {
        if (!is_array($order)) {
            throw new \InvalidArgumentException(
                'An invoice order has an invalid format.'
            );
        }

        $quantity = (float) ($order['quantity'] ?? 0);
        $unitPrice = (float) ($order['unitPrice'] ?? 0);

        $aggregate = trim((string) ($order['aggregate'] ?? ''));

        if ($aggregate === '') {
            throw new \InvalidArgumentException(
                'An order must have an aggregate type.'
            );
        }

        $aggregateType = $this->db->table('aggregate_types')
            ->select('id, name')
            ->where('name', $aggregate)
            ->where('is_active', true)
            ->get()
            ->getRowArray();

        if (
            !$aggregateType &&
            !in_array($aggregate, ['00', '01', '02'], true)
        ) {
            throw new \InvalidArgumentException(
                'An order has an invalid or inactive aggregate type.'
            );
        }

        if ($aggregateType) {
            $aggregate = (string) $aggregateType['name'];
        }

        if (!is_finite($quantity) || !is_finite($unitPrice)) {
            throw new \InvalidArgumentException(
                'Order quantities and prices must be valid numbers.'
            );
        }

        if ($quantity <= 0 || $unitPrice < 0) {
            throw new \InvalidArgumentException(
                'Order quantity must be greater than zero and unit price cannot be negative.'
            );
        }

        $lineTotal = round($quantity * $unitPrice, 2);

        $unit = trim((string) ($order['unit'] ?? ''));

        if ($unit === '') {
            $unit = 'm³';
        }

        $royaltyOrderNumber =
            (int) ($order['royaltyOrderNumber'] ?? 0);

        if ($royaltyOrderNumber <= 0) {
            $royaltyOrderNumber = $index + 1;
        }

        /*
         * Preserve the contract order's original business date.
         *
         * The date is already a calendar date, so do not convert it
         * through a server timezone or shift it to another day.
         */
        $orderDate = null;

        if (
            array_key_exists('orderDate', $order) &&
            $order['orderDate'] !== null &&
            $order['orderDate'] !== ''
        ) {
            $rawOrderDate = $order['orderDate'];

            if (
                is_string($rawOrderDate) &&
                preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    trim($rawOrderDate)
                )
            ) {
                $orderDate = trim($rawOrderDate);
            } elseif (is_numeric($rawOrderDate)) {
                $timestamp = (int) $rawOrderDate;

                if ($timestamp > 9999999999) {
                    $timestamp = (int) floor($timestamp / 1000);
                }

                $orderDate = gmdate('Y-m-d', $timestamp);
            } else {
                throw new \InvalidArgumentException(
                    'An order contains an invalid order date.'
                );
            }
        }

        $normalizedOrders[] = [
            'aggregate' => $aggregate,
            'quantity' => $quantity,
            'unit' => $unit,
            'unitPrice' => $unitPrice,
            'lineTotalExclVat' => $lineTotal,
            'royaltyOrderNumber' => $royaltyOrderNumber,
            'royaltyInvoiceNumber' =>
                trim((string) ($order['royaltyInvoiceNumber'] ?? '')) !== ''
                    ? trim((string) $order['royaltyInvoiceNumber'])
                    : null,
            'contractOrderId' =>
                $order['contractOrderId'] ?? null,
            'creditContractOrderId' =>
                $order['creditContractOrderId'] ?? null,
            'orderDate' => $orderDate,
        ];

        $subtotal += $lineTotal;
    }

    $subtotal = round($subtotal, 2);
    $vat = round($subtotal * 0.15, 2);
    $total = round($subtotal + $vat, 2);

    $withholdingRate = $withholdingEnabled ? 0.03 : 0.0;

    $withholdingAmount = round(
        $total * $withholdingRate,
        2
    );

    return [
        'orders' => $normalizedOrders,
        'subtotalExclVat' => $subtotal,
        'totalVat' => $vat,
        'totalPriceInclVat' => $total,
        'withholdingEnabled' => $withholdingEnabled,
        'withholdingRate' => $withholdingRate,
        'withholdingAmount' => $withholdingAmount,
        'netAmountAfterWithholding' => round(
            $total - $withholdingAmount,
            2
        ),
    ];
}


    private function insertLines(
        string $invoiceId,
        array $orders,
        ?string $creditContractId = null
    ): void {
        foreach ($orders as $index => $order) {
            $contractOrderId = $order['contractOrderId'] ?? null;
            $creditContractOrderId =
                $order['creditContractOrderId'] ?? null;

            /*
             * Backward compatibility:
             * Credit Contracts currently send contractOrderId.
             * When the invoice belongs to a credit contract, interpret
             * that value as a credit contract order ID.
             */
            if (
                $creditContractId !== null &&
                $creditContractOrderId === null &&
                $contractOrderId !== null
            ) {
                $creditContractOrderId = $contractOrderId;
                $contractOrderId = null;
            }

            if (
                $contractOrderId !== null &&
                !$this->validUuid($contractOrderId)
            ) {
                throw new \InvalidArgumentException(
                    'An order contains an invalid standard contract order ID.'
                );
            }

            if (
                $creditContractOrderId !== null &&
                !$this->validUuid($creditContractOrderId)
            ) {
                throw new \InvalidArgumentException(
                    'An order contains an invalid credit contract order ID.'
                );
            }

            if (
                $contractOrderId !== null &&
                $creditContractOrderId !== null
            ) {
                throw new \InvalidArgumentException(
                    'An invoice line cannot reference both standard and credit contract orders.'
                );
            }

            if ($creditContractOrderId !== null) {
                $exists = $this->db->table('credit_contract_orders')
                    ->where('id', $creditContractOrderId)
                    ->countAllResults();

                if ($exists === 0) {
                    throw new \InvalidArgumentException(
                        'The referenced credit contract order was not found.'
                    );
                }
            }

            $this->db->table('sales_invoice_lines')->insert([
                'id' => $this->uuid(),
                'invoice_id' => $invoiceId,
                'order_number' => $index + 1,
                'aggregate' => $order['aggregate'],
                'quantity' => $order['quantity'],
                'unit' => $order['unit'],
                'unit_price' => $order['unitPrice'],
                'line_total_excl_vat' => $order['lineTotalExclVat'],
                'royalty_order_number' => $order['royaltyOrderNumber'],
                'royalty_invoice_number' =>
                    $order['royaltyInvoiceNumber'],
                'contract_order_id' => $contractOrderId,
                'credit_contract_order_id' => $creditContractOrderId,
                'order_date' => $order['orderDate'],
                'royalty_amount' => round(
                    $order['lineTotalExclVat'] * 0.03,
                    2
                ),
            ]);
        }
    }

    /* -------------------------------------------------------------
     * Read attachment metadata referenced by an invoice
     * ----------------------------------------------------------- */

    private function loadAttachment(mixed $id): ?array
    {
        if (!is_string($id) || !$this->validUuid($id)) {
            return null;
        }

        $attachment = $this->db->table('attachments')
            ->select('id, file_name, storage_key, mime_type, file_size')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return null;
        }

        $storageKey = (string) $attachment['storage_key'];

        // Only permit files stored in the invoices directory.
        if (!preg_match(
            '/^invoices\/[0-9a-f-]{36}\.(pdf|jpg|png|webp)$/i',
            $storageKey
        )) {
            return null;
        }

        $filePath = ROOTPATH
            . 'file'
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);

        $fileExists = is_file($filePath) && is_readable($filePath);

        return [
            'id' => $attachment['id'],
            'fileName' => $attachment['file_name'],
            'fileUrl' => '/api/sales/invoices/attachments/' . $attachment['id'],
            'mimeType' => $attachment['mime_type'],
            'fileSize' => (int) $attachment['file_size'],
            'available' => $fileExists,
        ];
    }

    /* -------------------------------------------------------------
     * Read and map database invoice
     * ----------------------------------------------------------- */

    
private function loadInvoice(string $id): ?array
{
    $invoice = $this->db->table('sales_invoices')
        ->where('id', $id)
        ->get()
        ->getRowArray();

    if (!$invoice) {
        return null;
    }

    $lines = $this->db->table('sales_invoice_lines')
        ->where('invoice_id', $id)
        ->orderBy('order_number', 'ASC')
        ->get()
        ->getResultArray();

    $orders = [];

    foreach ($lines as $line) {
        $orders[] = [
            'id' => $line['id'],
            'orderNumber' => (int) $line['order_number'],
            'aggregate' => $line['aggregate'],
            'quantity' => (float) $line['quantity'],
            'unit' => $line['unit'] ?: 'm³',
            'unitPrice' => (float) $line['unit_price'],
            'lineTotalExclVat' =>
                (float) $line['line_total_excl_vat'],
            'royaltyOrderNumber' =>
                (int) $line['royalty_order_number'],
            'royaltyInvoiceNumber' =>
                $line['royalty_invoice_number'],
            'contractOrderId' =>
                $line['contract_order_id'] ?? null,
            'creditContractOrderId' =>
                $line['credit_contract_order_id'] ?? null,
            'orderDate' => $line['order_date'],
        ];
    }

    return [
        'id' => $invoice['id'],
        'invoiceNumber' => $invoice['invoice_number'],
        'date' => $this->toMilliseconds(
            $invoice['invoice_date']
        ),

        'customer' => [
            'name' => $invoice['customer_name_snapshot'],
            'tin' => $invoice['customer_tin_snapshot'],
        ],

        'fsNo' => $invoice['fs_no'],

        // Standard contract relationship ONLY.
        'contractId' => $invoice['contract_id'] ?? null,
        'isContract' => (bool)($invoice['is_contract'] ?? false),

        // Credit contract relationship ONLY.
        'creditContractId' =>
            $invoice['credit_contract_id'] ?? null,

        'orders' => $orders,

        'financialSummary' => [
            'subtotalExclVat' =>
                (float) $invoice['subtotal_excl_vat'],
            'totalVat' => (float) $invoice['total_vat'],
            'totalPriceInclVat' =>
                (float) $invoice['total_price_incl_vat'],
            'withholdingEnabled' => $this->boolValue(
                $invoice['withholding_enabled'] ?? false
            ),
            'withholdingRate' =>
                (float) $invoice['withholding_rate'],
            'withholdingAmount' =>
                (float) $invoice['withholding_amount'],
            'netAmountAfterWithholding' =>
                (float) $invoice['net_amount_after_withholding'],
        ],

        'payment' => [
            'bank' => $invoice['bank'],
            'paymentSender' => $invoice['payment_sender'],
            'paymentReferenceNumber' =>
                $invoice['payment_reference_number'],
            'paymentEvidence' => $this->loadAttachment(
                $invoice['payment_evidence_attachment_id'] ?? null
            ),
        ],

        'withholdingEvidence' => $this->loadAttachment(
            $invoice['withholding_evidence_attachment_id'] ?? null
        ),

        'remark' => $invoice['remark'],
        'status' => $invoice['status'],
        'rejectionReason' => $invoice['rejection_reason'],

        'createdAt' => $this->toMilliseconds(
            $invoice['created_at']
        ),
        'updatedAt' => $this->toMilliseconds(
            $invoice['updated_at']
        ),
        'submittedAt' => $this->toMilliseconds(
            $invoice['submitted_at']
        ),
        'financeReviewedAt' => $this->toMilliseconds(
            $invoice['finance_reviewed_at']
        ),
        'approvedAt' => $this->toMilliseconds(
            $invoice['approved_at']
        ),
        'rejectedAt' => $this->toMilliseconds(
            $invoice['rejected_at']
        ),
        'paymentConfirmedAt' => $this->toMilliseconds(
            $invoice['payment_confirmed_at']
        ),

        'createdBy' => $invoice['created_by'],
        'submittedBy' => $invoice['submitted_by'],
        'approvedBy' => $invoice['approved_by'],
        'paymentConfirmedBy' => $invoice['payment_confirmed_by'],
    ];
}


    /* -------------------------------------------------------------
     * Create and update
     * ----------------------------------------------------------- */

    private function saveInvoice(
        array $input,
        ?string $id = null
    ): array {
        $current = $id !== null ? $this->loadInvoice($id) : null;

        if ($id !== null && $current === null) {
            throw new \InvalidArgumentException('Invoice not found.');
        }

        /*
         * Merge partial draft updates with existing values.
         * Explicit null values remain explicit where supported.
         */
        if ($current !== null) {
            $currentPayment = $current['payment'];

            $input['customer'] = array_replace(
                $current['customer'],
                is_array($input['customer'] ?? null)
                    ? $input['customer']
                    : []
            );

            $input['payment'] = array_replace(
                $currentPayment,
                is_array($input['payment'] ?? null)
                    ? $input['payment']
                    : []
            );

            if (!array_key_exists('fsNo', $input)) {
                $input['fsNo'] = $current['fsNo'];
            }

            if (!array_key_exists('orders', $input)) {
                $input['orders'] = $current['orders'];
            }

            if (!array_key_exists('date', $input)) {
                $input['date'] = $current['date'];
            }

            if (!array_key_exists('contractId', $input)) {
                $input['contractId'] = $current['contractId'];
            }

            if (!array_key_exists('creditContractId', $input)) {
                $input['creditContractId'] = $current['creditContractId'] ?? null;
            }

            if (!array_key_exists('remark', $input)) {
                $input['remark'] = $current['remark'];
            }

            if (!array_key_exists('withholdingEnabled', $input)) {
                $input['withholdingEnabled'] =
                    $current['financialSummary']['withholdingEnabled'];
            }

            if (
                !array_key_exists('paymentEvidence', $input['payment'])
            ) {
                $input['payment']['paymentEvidence'] =
                    $currentPayment['paymentEvidence'];
            }

            if (!array_key_exists('withholdingEvidence', $input)) {
                $input['withholdingEvidence'] =
                    $current['withholdingEvidence'];
            }
        }

        $customer = $input['customer'] ?? [];
        $orders = $input['orders'] ?? [];
        $payment = $input['payment'] ?? [];

        if (!is_array($customer)) {
            throw new \InvalidArgumentException(
                'Customer data is invalid.'
            );
        }

        if (
            trim((string) ($customer['name'] ?? '')) === '' ||
            trim((string) ($customer['tin'] ?? '')) === ''
        ) {
            throw new \InvalidArgumentException(
                'Customer name and TIN are required.'
            );
        }

        if (trim((string) ($input['fsNo'] ?? '')) === '') {
            throw new \InvalidArgumentException('FS No is required.');
        }

        if (!is_array($orders) || count($orders) === 0) {
            throw new \InvalidArgumentException(
                'At least one invoice order is required.'
            );
        }

        if (!is_array($payment)) {
            throw new \InvalidArgumentException(
                'Payment data is invalid.'
            );
        }

        $withholdingEnabled = $this->boolValue(
            $input['withholdingEnabled'] ?? false
        );

        $financial = $this->calculate(
            $orders,
            $withholdingEnabled
        );

        $contractId = $input['contractId'] ?? null;
        $creditContractId = $input['creditContractId'] ?? null;

        /*
         * Backward compatibility with the current Credit Contracts frontend.
         * Its finalization sends contractId, so detect whether that UUID
         * belongs to credit_contracts or the standard contracts table.
         */
        if (
            $contractId !== null &&
            $creditContractId === null &&
            $this->validUuid($contractId)
        ) {
            $creditContractExists = $this->db->table('credit_contracts')
                ->where('id', $contractId)
                ->countAllResults();

            if ($creditContractExists > 0) {
                $creditContractId = $contractId;
                $contractId = null;
            }
        }

        if (
            $contractId !== null &&
            !$this->validUuid($contractId)
        ) {
            throw new \InvalidArgumentException(
                'The standard contract ID is invalid.'
            );
        }

        if (
            $creditContractId !== null &&
            !$this->validUuid($creditContractId)
        ) {
            throw new \InvalidArgumentException(
                'The credit contract ID is invalid.'
            );
        }

        if (
            $contractId !== null &&
            $creditContractId !== null
        ) {
            throw new \InvalidArgumentException(
                'An invoice cannot belong to both a standard contract and a credit contract.'
            );
        }

        if ($creditContractId !== null) {
            $creditContractExists = $this->db->table('credit_contracts')
                ->where('id', $creditContractId)
                ->countAllResults();

            if ($creditContractExists === 0) {
                throw new \InvalidArgumentException(
                    'Credit contract not found.'
                );
            }
        }

        $paymentEvidence = $payment['paymentEvidence'] ?? null;
        $withholdingEvidence =
            $input['withholdingEvidence'] ?? null;

        $paymentEvidenceId = is_array($paymentEvidence)
            ? $this->validAttachmentId($paymentEvidence['id'] ?? null)
            : null;

        $withholdingEvidenceId = is_array($withholdingEvidence)
            ? $this->validAttachmentId(
                $withholdingEvidence['id'] ?? null
            )
            : null;

        $now = date('Y-m-d H:i:s');

        $data = [
            'customer_name_snapshot' =>
                trim((string) $customer['name']),
            'customer_tin_snapshot' =>
                trim((string) $customer['tin']),
            'fs_no' => trim((string) $input['fsNo']),
            'contract_id' => $contractId,
            'is_contract' => $contractId !== null,
            'credit_contract_id' => $creditContractId,
            'invoice_date' => $this->toDate(
                $input['date'] ?? date('Y-m-d')
            ),
            'subtotal_excl_vat' =>
                $financial['subtotalExclVat'],
            'vat_rate' => 0.15,
            'total_vat' => $financial['totalVat'],
            'total_price_incl_vat' =>
                $financial['totalPriceInclVat'],
            'withholding_enabled' => $withholdingEnabled,
            'withholding_rate' =>
                $financial['withholdingRate'],
            'withholding_amount' =>
                $financial['withholdingAmount'],
            'net_amount_after_withholding' =>
                $financial['netAmountAfterWithholding'],
            'bank' => $payment['bank'] ?? null,
            'payment_sender' => $payment['paymentSender'] ?? null,
            'payment_reference_number' =>
                $payment['paymentReferenceNumber'] ?? null,
            'payment_evidence_attachment_id' =>
                $paymentEvidenceId,
            'withholding_evidence_attachment_id' =>
                $withholdingEvidenceId,
            'remark' => $input['remark'] ?? null,
            'updated_at' => $now,
        ];

        if ($id === null) {
            $id = $this->uuid();

            $data['id'] = $id;
            $data['invoice_number'] = $this->nextInvoiceNumber();
            $data['status'] = 'DRAFT';
            $data['created_by'] = $this->actorId();
            $data['created_at'] = $now;
            $data['version'] = 1;

            $this->db->table('sales_invoices')->insert($data);
        } else {
            $this->db->table('sales_invoices')
                ->where('id', $id)
                ->update($data);

            $oldLineIds = $this->db->table('sales_invoice_lines')->select('id')->where('invoice_id', $id)->get()->getResultArray();
            foreach ($oldLineIds as $oldLine) {
                $this->db->table('contract_orders')->where('sales_invoice_line_id', $oldLine['id'])->update(['sales_invoice_line_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $this->db->table('sales_invoice_lines')
                ->where('invoice_id', $id)
                ->delete();
        }

        $this->insertLines(
            $id,
            $financial['orders'],
            $creditContractId
        );

        $loaded = $this->loadInvoice($id);

        if (!$loaded) {
            throw new \RuntimeException(
                'The invoice could not be loaded after saving.'
            );
        }

        return $loaded;
    }

    /**
     * Return active aggregate types and their current price per m³.
     * Sales users need read-only access to this operational setting.
     */
    public function aggregateTypes()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $rows = $this->db->table('aggregate_types')
            ->select('id, name, price_per_m3, description')
            ->where('is_active', true)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        $data = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'pricePerM3' => (float) $row['price_per_m3'],
                'description' => $row['description'],
            ];
        }, $rows);

        return $this->respond([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function index()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $rows = $this->db->table('sales_invoices')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        $result = [];

        foreach ($rows as $row) {
            $invoice = $this->loadInvoice($row['id']);

            if ($invoice !== null) {
                $result[] = $invoice;
            }
        }

        return $this->respond([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function show($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $invoice = $this->loadInvoice((string) $id);

        if (!$invoice) {
            return $this->failNotFound('Invoice not found.');
        }

        return $this->respond([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    public function create()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $this->db->transBegin();

        try {
            $invoice = $this->saveInvoice($this->jsonInput());

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Database transaction failed.'
                );
            }

            $this->db->transCommit();

            return $this->respondCreated([
                'success' => true,
                'message' => 'Invoice created.',
                'data' => $invoice,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            if ($e instanceof \InvalidArgumentException) {
                if ($e->getMessage() === 'Invoice not found.') {
                    return $this->failNotFound($e->getMessage());
                }

                return $this->failValidationErrors($e->getMessage());
            }

            log_message(
                'error',
                'Sales invoice create failed: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Unable to create invoice.'
            );
        }
    }

    public function update($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $id = (string) $id;

        $existing = $this->db->table('sales_invoices')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$existing) {
            return $this->failNotFound('Invoice not found.');
        }

        if (!in_array($existing['status'], ['DRAFT', 'REJECTED'], true)) {
            return $this->fail(
                'Only draft or rejected invoices can be edited.',
                409
            );
        }

        $this->db->transBegin();

        try {
            $this->saveInvoice($this->jsonInput(), $id);

            $this->db->table('sales_invoices')
                ->where('id', $id)
                ->update([
                    'version' => ((int) ($existing['version'] ?? 1)) + 1,
                ]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Database transaction failed.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Invoice updated.',
                'data' => $this->loadInvoice($id),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            if ($e instanceof \InvalidArgumentException) {
                return $this->failValidationErrors($e->getMessage());
            }

            log_message(
                'error',
                'Sales invoice update failed: ' . $e->getMessage()
            );

            return $this->failServerError(
                'Unable to update invoice.'
            );
        }
    }

    /* -------------------------------------------------------------
     * Status transitions
     * ----------------------------------------------------------- */

    private function changeStatus(
        string $id,
        array $allowedStatuses,
        string $newStatus,
        array $extra = []
    ) {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $invoice = $this->db->table('sales_invoices')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$invoice) {
            return $this->failNotFound('Invoice not found.');
        }

        if (!in_array($invoice['status'], $allowedStatuses, true)) {
            return $this->fail(
                'This action is not allowed for the current invoice status.',
                409
            );
        }

        if ($newStatus === 'PENDING_FINANCE_REVIEW') {
            $errors = [];

            if (trim((string) $invoice['customer_name_snapshot']) === '') {
                $errors[] = 'Customer name is required.';
            }

            if (trim((string) $invoice['customer_tin_snapshot']) === '') {
                $errors[] = 'Customer TIN is required.';
            }

            if (trim((string) $invoice['fs_no']) === '') {
                $errors[] = 'FS No is required.';
            }

            if (empty($invoice['bank'])) {
                $errors[] = 'Bank is required.';
            }

            if (empty($invoice['payment_sender'])) {
                $errors[] = 'Payment sender is required.';
            }

            if (empty($invoice['payment_reference_number'])) {
                $errors[] = 'Payment reference is required.';
            }

            if (empty($invoice['payment_evidence_attachment_id'])) {
                $errors[] = 'Payment evidence must be uploaded before submission.';
            }

            if (
                $this->boolValue($invoice['withholding_enabled'] ?? false) &&
                empty($invoice['withholding_evidence_attachment_id'])
            ) {
                $errors[] = 'Withholding evidence must be uploaded.';
            }

            $lineCount = $this->db->table('sales_invoice_lines')
                ->where('invoice_id', $id)
                ->countAllResults();

            if ($lineCount < 1) {
                $errors[] = 'At least one order is required.';
            }

            if ($errors !== []) {
                return $this->failValidationErrors($errors);
            }
        }

        $now = date('Y-m-d H:i:s');
        $actorId = $this->actorId();

        $changes = array_merge([
            'status' => $newStatus,
            'updated_at' => $now,
            'version' => ((int) ($invoice['version'] ?? 1)) + 1,
        ], $extra);

        // Set audit values only after successful authentication.
        if ($newStatus === 'PENDING_FINANCE_REVIEW') {
            $changes['submitted_by'] = $actorId;
            $changes['submitted_at'] = $now;
            $changes['rejection_reason'] = null;
        } elseif ($newStatus === 'FINANCE_APPROVED') {
            $changes['finance_reviewed_at'] = $now;
            $changes['approved_at'] = $now;
            $changes['approved_by'] = $actorId;
        } elseif ($newStatus === 'REJECTED') {
            $changes['finance_reviewed_at'] = $now;
            $changes['rejected_at'] = $now;
        } elseif ($newStatus === 'PAYMENT_CONFIRMED') {
            $changes['payment_confirmed_at'] = $now;
            $changes['payment_confirmed_by'] = $actorId;
        }

        // Update invoice and linked contract order in one transaction.
        $this->db->transBegin();
        try {
            $builder = $this->db->table('sales_invoices')
                ->where('id', $id)
                ->whereIn('status', $allowedStatuses);
            $builder->update($changes);
            if ($this->db->affectedRows() !== 1) {
                throw new \DomainException('The invoice status changed concurrently. Refresh and try again.');
            }

            if (!empty($invoice['contract_id']) && !empty($invoice['is_contract'])) {
                $lines = $this->db->table('sales_invoice_lines')
                    ->where('invoice_id', $id)
                    ->where('contract_order_id IS NOT NULL', null, false)
                    ->get()->getResultArray();
                foreach ($lines as $line) {
                    $order = $this->db->query('SELECT * FROM contract_orders WHERE id = ? FOR UPDATE', [$line['contract_order_id']])->getRowArray();
                    if (!$order || (string) $order['contract_id'] !== (string) $invoice['contract_id']) {
                        throw new \RuntimeException('The invoice contains an invalid linked contract order.');
                    }
                    if ($newStatus === 'FINANCE_APPROVED') {
                        if (strtoupper((string) $order['status']) !== 'PENDING') {
                            throw new \DomainException('Only pending contract orders can be approved.');
                        }
                        $this->db->table('contract_orders')->where('id', $order['id'])->update([
                            'status' => 'COMPLETED', 'completed_by' => $actorId,
                            'completed_at' => $now, 'updated_at' => $now,
                        ]);
                    } elseif ($newStatus === 'PAYMENT_CONFIRMED') {
                        if (strtoupper((string) $order['status']) !== 'COMPLETED') {
                            throw new \DomainException('Only approved contract orders can have payment confirmed.');
                        }
                        $this->db->table('contract_orders')->where('id', $order['id'])->update([
                            'status' => 'PAYMENT_CONFIRMED', 'updated_at' => $now,
                        ]);
                    }
                    // Rejection intentionally leaves the order PENDING so it can be edited and resubmitted.
                }
            }

            if ($this->db->transStatus() === false) throw new \RuntimeException('Database transaction failed.');
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof \DomainException) return $this->fail($e->getMessage(), 409);
            log_message('error', 'Sales invoice status transition failed: ' . $e->getMessage());
            return $this->failServerError('Unable to update invoice status and synchronize its contract order.');
        }

        return $this->respond([
            'success' => true,
            'message' => 'Invoice status updated.',
            'data' => $this->loadInvoice($id),
        ]);
    }

    public function submit($id = null)
    {
        return $this->changeStatus(
            (string) $id,
            ['DRAFT', 'REJECTED'],
            'PENDING_FINANCE_REVIEW'
        );
    }

    public function approve($id = null)
    {
        return $this->changeStatus(
            (string) $id,
            ['PENDING_FINANCE_REVIEW'],
            'FINANCE_APPROVED'
        );
    }

    public function reject($id = null)
    {
        $input = $this->jsonInput();

        $reason = trim(
            (string) (
                $input['reason'] ??
                $input['rejectionReason'] ??
                ''
            )
        );

        if ($reason === '') {
            return $this->failValidationErrors(
                'A rejection reason is required.'
            );
        }

        return $this->changeStatus(
            (string) $id,
            ['PENDING_FINANCE_REVIEW'],
            'REJECTED',
            [
                'rejection_reason' => $reason,
            ]
        );
    }

    public function confirmPayment($id = null)
    {
        return $this->changeStatus(
            (string) $id,
            ['FINANCE_APPROVED'],
            'PAYMENT_CONFIRMED'
        );
    }

    public function uploadAttachment(): ResponseInterface
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Please select a valid file.',
                ]);
        }

        // Maximum file size: 10 MB.
        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->response
                ->setStatusCode(413)
                ->setJSON([
                    'success' => false,
                    'message' => 'The maximum file size is 10 MB.',
                ]);
        }

        $tempPath = $file->getTempName();

        if (!is_file($tempPath) || !is_readable($tempPath)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to read the uploaded file.',
                ]);
        }

        /*
         * Detect actual file content without using finfo.
         */
        $mimeType = null;

        // Detect PDF using its file signature.
        $fileHeader = file_get_contents($tempPath, false, null, 0, 5);

        if ($fileHeader === '%PDF-') {
            $mimeType = 'application/pdf';
        } else {
            // Detect supported image formats.
            $imageInfo = @getimagesize($tempPath);

            if ($imageInfo !== false && !empty($imageInfo['mime'])) {
                $mimeType = $imageInfo['mime'];
            }
        }

        $allowedTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if ($mimeType === null || !isset($allowedTypes[$mimeType])) {
            return $this->response
                ->setStatusCode(415)
                ->setJSON([
                    'success' => false,
                    'message' => 'Only valid PDF, JPEG, PNG, and WebP files are allowed.',
                ]);
        }

        $attachmentId = $this->uuid();

        $extension = $allowedTypes[$mimeType];

        $storedFileName = $attachmentId . '.' . $extension;

        /*
         * Physical storage:
         *
         * backend/file/invoices/
         *
         * ROOTPATH points to the CodeIgniter project root.
         */
        $uploadDirectory = ROOTPATH
            . 'file'
            . DIRECTORY_SEPARATOR
            . 'invoices';

        $storageKey = 'invoices/' . $storedFileName;

        if (!is_dir($uploadDirectory)) {
            if (
                !mkdir($uploadDirectory, 0750, true) &&
                !is_dir($uploadDirectory)
            ) {
                log_message(
                    'error',
                    'Unable to create invoice directory: ' . $uploadDirectory
                );

                return $this->response
                    ->setStatusCode(500)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Unable to create the invoice upload directory.',
                    ]);
            }
        }

        if (!is_writable($uploadDirectory)) {
            log_message(
                'error',
                'Invoice upload directory is not writable: ' . $uploadDirectory
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'The invoice upload directory is not writable.',
                ]);
        }

        $originalName = $file->getClientName();
        $fileSize = (int) $file->getSize();

        $savedPath = $uploadDirectory
            . DIRECTORY_SEPARATOR
            . $storedFileName;

        try {
            /*
             * STEP 1: Save the actual physical file.
             */
            $file->move($uploadDirectory, $storedFileName);

            if (!is_file($savedPath)) {
                throw new \RuntimeException(
                    'The uploaded file was not physically saved.'
                );
            }

            /*
             * STEP 2: Insert attachment metadata.
             */
            $inserted = $this->db->table('attachments')->insert([
                'id' => $attachmentId,
                'file_name' => $originalName,
                'storage_key' => $storageKey,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'uploaded_by' => $this->actorId(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$inserted) {
                throw new \RuntimeException(
                    'Attachment metadata could not be inserted into the database.'
                );
            }

            /*
             * STEP 3: Return the attachment information.
             */
            return $this->response
                ->setStatusCode(201)
                ->setJSON([
                    'success' => true,
                    'message' => 'Attachment uploaded successfully.',
                    'attachment' => [
                        'id' => $attachmentId,
                        'fileName' => $originalName,
                        'fileUrl' => '/api/sales/invoices/attachments/' . $attachmentId,
                        'mimeType' => $mimeType,
                        'fileSize' => $fileSize,
                    ],
                ]);

        } catch (Throwable $e) {

            /*
             * Remove the physical file if database insertion fails.
             */
            if (is_file($savedPath)) {
                @unlink($savedPath);
            }

            log_message(
                'error',
                'Sales invoice attachment upload failed: {message}',
                [
                    'message' => $e->getMessage(),
                ]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'The invoice attachment could not be saved.',
                ]);
        }
    }

    public function viewAttachment($id = null): ResponseInterface
    {
        // 1. Authenticate.
        if ($response = $this->authenticate()) {
            return $response;
        }

        // 2. Validate UUID.
        if (!$this->validUuid($id)) {
            return $this->failNotFound('Attachment not found.');
        }

        // 3. Retrieve attachment metadata.
        $attachment = $this->db->table('attachments')
            ->select('id, storage_key, mime_type, file_name')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->failNotFound('Attachment not found.');
        }

        // 4. Validate storage key.
        $storageKey = (string) $attachment['storage_key'];

        if (!preg_match(
            '/^invoices\/[0-9a-f-]{36}\.(pdf|jpg|png|webp)$/i',
            $storageKey
        )) {
            log_message(
                'error',
                'Invalid invoice attachment storage key: ' . $storageKey
            );

            return $this->failNotFound('Invalid attachment path.');
        }

        // 5. Resolve physical file path.
        $filePath = ROOTPATH
            . 'file'
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);

        if (!is_file($filePath) || !is_readable($filePath)) {
            log_message(
                'error',
                'Invoice attachment file missing: ' . $filePath
            );

            return $this->failNotFound(
                'Attachment file not found on the server.'
            );
        }

        // 6. Validate MIME type.
        $mimeType = (string) $attachment['mime_type'];

        $allowedTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        if (!in_array($mimeType, $allowedTypes, true)) {
            return $this->fail(
                'Unsupported attachment type.',
                415
            );
        }

        // 7. Read original binary content.
        $content = file_get_contents($filePath);

        if ($content === false) {
            log_message(
                'error',
                'Unable to read invoice attachment: ' . $filePath
            );

            return $this->failServerError(
                'Unable to read attachment.'
            );
        }

        // 8. Prepare filename.
        $fileName = basename(
            str_replace(
                ["\r", "\n", '"'],
                '',
                (string) $attachment['file_name']
            )
        );

        // 9. Log original file information.
        log_message(
            'error',
            'ATTACHMENT: Original size = ' . strlen($content)
        );

        log_message(
            'error',
            'ATTACHMENT: Original SHA256 = ' . hash('sha256', $content)
        );

        log_message(
            'error',
            'ATTACHMENT: Original first bytes = '
            . bin2hex(substr($content, 0, 8))
        );

        // 10. Clear existing output buffers.
        // This endpoint must return only the attachment binary data.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 11. Build binary response.
        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $mimeType)
            ->setHeader(
                'Content-Disposition',
                'inline; filename="' . $fileName . '"'
            )
            ->setHeader(
                'Content-Length',
                (string) strlen($content)
            )
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($content);
    }


}