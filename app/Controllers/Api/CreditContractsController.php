<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class CreditContractsController extends ResourceController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    private const VAT_RATE = 0.15;
    private const WITHHOLDING_RATE = 0.03;
    private const ROYALTY_RATE = 0.03;

    private const ATTACHMENT_DIRECTORY = 'file/credit_contract/attachments';
    private const PAYMENT_DIRECTORY = 'file/credit_contract/payment_evidence';
    private const WITHHOLDING_DIRECTORY = 'file/credit_contract/witholding';

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /* ============================================================
     * AUTHENTICATION AND AUTHORIZATION
     * ============================================================ */

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

    private function actorId(): string
    {
        return (string) ($this->authenticatedUser['id'] ?? '');
    }

    private function role(): string
    {
        return strtoupper(trim((string) ($this->authenticatedUser['role'] ?? '')));
    }

    private function isFinance(): bool
    {
        return in_array($this->role(), ['FINANCE', 'FINANCE_MANAGER'], true);
    }

    private function isSales(): bool
    {
        return in_array($this->role(), ['SALES', 'SALES_MANAGER'], true);
    }

    private function requireFinance(): ?ResponseInterface
    {
        return $this->isFinance()
            ? null
            : $this->failForbidden('Only Finance users can perform this action.');
    }

    private function requireSalesOrFinance(): ?ResponseInterface
    {
        return ($this->isSales() || $this->isFinance())
            ? null
            : $this->failForbidden('You are not authorized to perform this action.');
    }

    /* ============================================================
     * GENERAL HELPERS
     * ============================================================ */

    
private function input(): array
{
    $contentType = $this->request->getHeaderLine('Content-Type');

    // Handle multipart/form-data and application/x-www-form-urlencoded.
    if (
        stripos($contentType, 'multipart/form-data') !== false ||
        stripos($contentType, 'application/x-www-form-urlencoded') !== false
    ) {
        return $this->request->getPost() ?? [];
    }

    // Handle JSON requests safely.
    $rawBody = $this->request->getBody();

    if ($rawBody === '' || $rawBody === null) {
        return $this->request->getPost() ?? [];
    }

    try {
        $data = $this->request->getJSON(true);

        if (is_array($data)) {
            return $data;
        }
    } catch (\CodeIgniter\HTTP\Exceptions\HTTPException $e) {
        log_message('warning', 'Invalid JSON request body: {message}', [
            'message' => $e->getMessage(),
        ]);
    }

    $postData = $this->request->getPost();

    return is_array($postData) ? $postData : [];
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

    private function validUuid($value): bool
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function amount($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (!is_finite($number) || $number < 0) {
            return null;
        }

        return round($number, 2);
    }

    private function toDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Handle Unix timestamps in milliseconds from the frontend.
        if (is_numeric($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            try {
                $date = new \DateTime('@' . $timestamp);
                $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

                return $date->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (is_string($value)) {
            $value = trim($value);

            $date = \DateTime::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value
                ? $value
                : null;
        }

        return null;
    }

    private function jsonValue($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function logError(string $message, Throwable $e): void
    {
        log_message('error', $message . ': ' . $e->getMessage());
    }

    private function attachmentDetails($id): ?array
    {
        if (!$this->validUuid($id)) {
            return null;
        }

        $row = $this->db->table('attachments')
            ->select('id, file_name, storage_key, mime_type, file_size')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return null;
        }

        return [
            'id' => $row['id'],
            'fileName' => $row['file_name'],
            'fileUrl' => '/api/credit-contracts/attachments/' . $row['id'],
            'mimeType' => $row['mime_type'],
            'fileSize' => (int) $row['file_size'],
        ];
    }

    private function attachmentId($evidence): ?string
    {
        if (!is_array($evidence)) {
            return null;
        }

        $id = $evidence['id'] ?? null;

        if (!$this->validUuid($id)) {
            return null;
        }

        return $this->db->table('attachments')
            ->where('id', $id)
            ->countAllResults() > 0 ? $id : null;
    }

    private function attachmentMimeAllowed($id): bool
    {
        $attachment = $this->attachmentDetails($id);

        return $attachment !== null && in_array(
            strtolower((string) $attachment['mimeType']),
            ['application/pdf', 'image/jpeg', 'image/png'],
            true
        );
    }

    private function uploadCreditFile(string $field, string $directory): ?array
    {
        $file = $this->request->getFile($field);

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return null;
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \RuntimeException('Files must not exceed 10 MB.');
        }

        $mime = strtolower((string) $file->getMimeType());

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        if (!isset($allowed[$mime])) {
            throw new \RuntimeException('Only PDF, JPG and PNG files are allowed.');
        }

        $id = $this->uuid();
        $extension = $allowed[$mime];
        $fileName = $file->getClientName();
        $storageKey = $directory . '/' . $id . '.' . $extension;

        $absoluteDirectory = ROOTPATH . $directory;

        if (
            !is_dir($absoluteDirectory) &&
            !mkdir($absoluteDirectory, 0755, true) &&
            !is_dir($absoluteDirectory)
        ) {
            throw new \RuntimeException('Unable to create the attachment directory.');
        }

        $file->move($absoluteDirectory, $id . '.' . $extension);

        try {
            $this->db->table('attachments')->insert([
                'id' => $id,
                'file_name' => $fileName,
                'storage_key' => $storageKey,
                'mime_type' => $mime,
                'file_size' => $file->getSize(),
                'uploaded_by' => $this->actorId(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $path = ROOTPATH . $storageKey;

            if (is_file($path)) {
                unlink($path);
            }

            throw $e;
        }

        return $this->attachmentDetails($id);
    }

    private function deleteAttachmentFile(string $id): void
    {
        $row = $this->db->table('attachments')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return;
        }

        $storageKey = (string) ($row['storage_key'] ?? '');

        if (
            str_starts_with($storageKey, self::ATTACHMENT_DIRECTORY . '/') ||
            str_starts_with($storageKey, self::PAYMENT_DIRECTORY . '/') ||
            str_starts_with($storageKey, self::WITHHOLDING_DIRECTORY . '/')
        ) {
            $path = ROOTPATH . $storageKey;

            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->db->table('attachments')->where('id', $id)->delete();
    }

    /* ============================================================
     * CONTRACT LOOKUP AND MAPPING
     * ============================================================ */

    private function findContract(string $id, bool $lock = false): ?array
    {
        if (!$this->validUuid($id)) {
            return null;
        }

        $sql = 'SELECT * FROM credit_contracts WHERE id = ?';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        return $this->db->query($sql, [$id])->getRowArray() ?: null;
    }

    private function getOrders(string $contractId): array
    {
        $rows = $this->db->table('credit_contract_orders')
            ->where('contract_id', $contractId)
            ->orderBy('order_number', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(
            fn ($row) => $this->mapOrder($row),
            $rows
        );
    }

    private function mapOrder(array $row): array
    {
        $completedBy = $row['completed_by'] ?? null;

        $invoice = $this->invoiceForOrder((string) $row['id']);

        return [
            'id' => $row['id'],
            'creditContractId' => $row['contract_id'],
            'orderNumber' => (int) $row['order_number'],
            'aggregate' => $row['aggregate'],
            'quantity' => (float) $row['quantity'],
            'unit' => $row['unit'],
            'unitPrice' => (float) $row['unit_price'],
            'lineTotalExclVat' => (float) $row['line_total_excl_vat'],
            'lineTotalInclVat' => round(
                (float) $row['line_total_excl_vat'] * (1 + self::VAT_RATE),
                2
            ),
            'royaltyInvoiceNumber' => $row['royalty_invoice_number'],
            'orderDate' => $row['order_date'],
            'status' => $row['status'],
            'completedByUserId' => $completedBy,
            'completedByName' => $completedBy,
            'completedByEmail' => '',
            'lastEditedByUserId' => $row['last_edited_by'] ?? null,
            'editedAt' => $row['edited_at'] ?? null,
            'cancelledBy' => $row['cancelled_by'] ?? null,
            'cancelledAt' => $row['cancelled_at'] ?? null,
            'cancellationReason' => $row['cancellation_reason'] ?? null,
            'salesInvoiceId' => $invoice['id'] ?? null,
            'salesInvoiceNumber' => $invoice['invoice_number'] ?? null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function mapContract(array $row, bool $includeOrders = false): array
{
    $invoices = $this->invoicesForContract((string) $row['id']);

    $attachments = $this->jsonValue(
        $row['attachments'] ?? []
    );

    if (!is_array($attachments)) {
        $attachments = [];
    }

    $attachments = array_values(array_filter(
        array_map(static function ($attachment) {
            if (is_string($attachment) || is_numeric($attachment)) {
                return (string) $attachment;
            }

            if (is_array($attachment)) {
                return $attachment['id']
                    ?? $attachment['attachmentId']
                    ?? null;
            }

            return null;
        }, $attachments),
        static fn ($attachment) =>
            is_string($attachment) && trim($attachment) !== ''
    ));

    $result = [
        'id' => $row['id'],
        'contractNumber' => $row['contract_number'],
        'customer' => [
            'name' => $row['customer_name'],
            'tin' => $row['customer_tin'],
        ],
        'contractValue' => (float) $row['contract_value'],
        'fsNo' => $row['fs_no'] ?? '',
        'contractDate' => $row['contract_date'],
        'attachments' => $attachments,
        'remark' => $row['remark'],
        'status' => $row['status'],
        'createdBy' => $row['created_by'],
        'activatedBy' => $row['activated_by'],
        'activatedAt' => $row['activated_at'],
        'finalizedBy' => $row['finalized_by'],
        'finalizedAt' => $row['finalized_at'],
        'finalizationReason' => $row['finalization_reason'],
        'cancelledBy' => $row['cancelled_by'],
        'cancelledAt' => $row['cancelled_at'],
        'cancellationReason' => $row['cancellation_reason'],
        'closedBelowValue' => (
            $row['status'] === 'INVOICED' &&
            (float) $row['contract_value'] >
                $this->fulfilledValue($row['id'])
        ),
        'salesInvoiceId' => $invoices[0]['id'] ?? null,
        'salesInvoiceNumber' => $invoices[0]['invoice_number'] ?? null,
        'salesInvoices' => array_map(
            static fn ($invoice) => [
                'id' => $invoice['id'],
                'invoiceNumber' => $invoice['invoice_number'],
                'status' => $invoice['status'],
            ],
            $invoices
        ),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
        'version' => (int) ($row['version'] ?? 1),
    ];

    if ($includeOrders) {
        $result['orders'] = $this->getOrders($row['id']);
    }

    return $result;
}

    private function fulfilledValue(string $contractId): float
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(line_total_excl_vat), 0) AS subtotal
             FROM credit_contract_orders
             WHERE contract_id = ?
               AND status = 'COMPLETED'",
            [$contractId]
        )->getRowArray();

        return round(
            (float) ($row['subtotal'] ?? 0) * (1 + self::VAT_RATE),
            2
        );
    }

    private function nextOrderNumber(string $contractId): int
    {
        $row = $this->db->query(
            'SELECT COALESCE(MAX(order_number), 0) + 1 AS next_number
             FROM credit_contract_orders
             WHERE contract_id = ?',
            [$contractId]
        )->getRowArray();

        return (int) ($row['next_number'] ?? 1);
    }

    /* ============================================================
     * SALES INVOICE INTEGRATION
     * One individual Sales Invoice is created for every order.
     * ============================================================ */

    private function nextInvoiceNumber(): string
    {
        $this->db->query('SELECT pg_advisory_xact_lock(734281)');

        $row = $this->db->query(
            "SELECT COALESCE(
                MAX(CAST(SUBSTRING(invoice_number FROM 5) AS BIGINT)),
                0
             ) AS last_number
             FROM sales_invoices
             WHERE invoice_number ~ '^INV-[0-9]+$'"
        )->getRowArray();

        return 'INV-' . str_pad(
            (string) (((int) ($row['last_number'] ?? 0)) + 1),
            5,
            '0',
            STR_PAD_LEFT
        );
    }

    private function invoiceForOrder(string $orderId): ?array
    {
        return $this->db->query(
            'SELECT si.*
             FROM sales_invoices si
             INNER JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
             WHERE sil.credit_contract_order_id = ?
             ORDER BY si.created_at ASC
             LIMIT 1',
            [$orderId]
        )->getRowArray() ?: null;
    }

    private function invoicesForContract(string $contractId): array
    {
        return $this->db->query(
            'SELECT DISTINCT si.*
             FROM sales_invoices si
             INNER JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
             INNER JOIN credit_contract_orders cco
                ON cco.id = sil.credit_contract_order_id
             WHERE cco.contract_id = ?
             ORDER BY si.created_at ASC',
            [$contractId]
        )->getResultArray();
    }

    private function createOrderInvoice(
    array $contract,
    array $order
): array {
    $subtotal = round((float) $order['line_total_excl_vat'], 2);
    $vat = round($subtotal * self::VAT_RATE, 2);
    $gross = round($subtotal + $vat, 2);
    $invoiceId = $this->uuid();
    $now = date('Y-m-d H:i:s');

    $invoiceNumber = $this->nextInvoiceNumber();

    $this->db->table('sales_invoices')->insert([
        'id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'customer_name_snapshot' => $contract['customer_name'],
        'customer_tin_snapshot' => $contract['customer_tin'],
        'fs_no' => null,
        'contract_id' => null,
        'credit_contract_id' => $contract['id'],
        'invoice_date' => $order['order_date'],
        'subtotal_excl_vat' => $subtotal,
        'vat_rate' => self::VAT_RATE,
        'total_vat' => $vat,
        'total_price_incl_vat' => $gross,
        'withholding_enabled' => false,
        'withholding_rate' => 0,
        'withholding_amount' => 0,
        'net_amount_after_withholding' => $gross,
        'bank' => null,
        'payment_sender' => null,
        'payment_reference_number' => null,
        'payment_evidence_attachment_id' => null,
        'withholding_evidence_attachment_id' => null,
        'remark' => 'Credit contract ' . $contract['contract_number'] .
            ' — Order #' . $order['order_number'],
        'status' => 'PENDING_FINANCE_REVIEW',
        'created_by' => $this->actorId(),
        'created_at' => $now,
        'updated_at' => $now,
        'version' => 1,
    ]);

    $this->db->table('sales_invoice_lines')->insert([
        'id' => $this->uuid(),
        'invoice_id' => $invoiceId,
        'order_number' => 1,
        'aggregate' => $order['aggregate'],
        'quantity' => $order['quantity'],
        'unit' => $order['unit'],
        'unit_price' => $order['unit_price'],
        'line_total_excl_vat' => $subtotal,
        'royalty_order_number' => $order['order_number'],
        'royalty_invoice_number' => $order['royalty_invoice_number'],
        'contract_order_id' => null,
        'credit_contract_order_id' => $order['id'],
        'order_date' => $order['order_date'],
        'royalty_amount' => round($subtotal * self::ROYALTY_RATE, 2),
    ]);

    return $this->db->table('sales_invoices')
        ->where('id', $invoiceId)
        ->get()
        ->getRowArray();
}

    private function updateOrderInvoice(array $order): void
    {
        $invoice = $this->invoiceForOrder((string) $order['id']);

        if (!$invoice) {
            throw new \RuntimeException(
                'The Sales Invoice for this credit contract order was not found.'
            );
        }

        if (!in_array($invoice['status'], ['DRAFT', 'REJECTED'], true)) {
            throw new \RuntimeException(
                'This order is linked to an invoice that can no longer be edited.'
            );
        }

        $subtotal = round((float) $order['line_total_excl_vat'], 2);
        $vat = round($subtotal * self::VAT_RATE, 2);
        $gross = round($subtotal + $vat, 2);

        $this->db->table('sales_invoices')
            ->where('id', $invoice['id'])
            ->update([
                'invoice_date' => $order['order_date'],
                'subtotal_excl_vat' => $subtotal,
                'vat_rate' => self::VAT_RATE,
                'total_vat' => $vat,
                'total_price_incl_vat' => $gross,
                'withholding_enabled' => false,
                'withholding_rate' => 0,
                'withholding_amount' => 0,
                'net_amount_after_withholding' => $gross,
                'updated_at' => date('Y-m-d H:i:s'),
                'version' => ((int) ($invoice['version'] ?? 1)) + 1,
            ]);

        $this->db->table('sales_invoice_lines')
            ->where('invoice_id', $invoice['id'])
            ->where('credit_contract_order_id', $order['id'])
            ->update([
                'aggregate' => $order['aggregate'],
                'quantity' => $order['quantity'],
                'unit' => $order['unit'],
                'unit_price' => $order['unit_price'],
                'line_total_excl_vat' => $subtotal,
                'royalty_order_number' => $order['order_number'],
                'royalty_invoice_number' => $order['royalty_invoice_number'],
                'order_date' => $order['order_date'],
                'royalty_amount' => round($subtotal * self::ROYALTY_RATE, 2),
            ]);
    }

    /* ============================================================
     * AUDIT HELPERS
     * ============================================================ */

    private function addStatusHistory(
        string $contractId,
        ?string $previous,
        string $next,
        string $action,
        ?string $reason = null
    ): void {
        $this->db->table('credit_contract_status_history')->insert([
            'id' => $this->uuid(),
            'contract_id' => $contractId,
            'previous_status' => $previous,
            'new_status' => $next,
            'action' => $action,
            'reason' => $reason,
            'actor_id' => $this->actorId(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function addFinancialHistory(
        string $contractId,
        string $action,
        ?string $note = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $this->db->table('credit_contract_financial_history')->insert([
            'id' => $this->uuid(),
            'contract_id' => $contractId,
            'action' => $action,
            'note' => $note,
            'old_values' => $oldValues === null ? null : json_encode($oldValues),
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'actor_id' => $this->actorId(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ============================================================
     * CONTRACT ATTACHMENTS
     * ============================================================ */

    public function indexAttachments($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        $contract = $this->findContract((string) $id);

        if (!$contract) {
            return $this->failNotFound('Credit contract not found.');
        }

        $rows = $this->db->table('credit_contract_attachments')
            ->where('contract_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                'id' => $row['id'],
                'contractId' => $row['contract_id'],
                'attachmentType' => $row['attachment_type'],
                'fileName' => $row['file_name'],
                'mimeType' => $row['mime_type'],
                'fileSize' => $row['file_size'] !== null
                    ? (int) $row['file_size']
                    : null,
                'createdAt' => $row['created_at'],
                'fileUrl' => '/api/credit-contracts/' .
                    $id . '/attachments/' . $row['id'],
            ];
        }

        return $this->respond([
            'success' => true,
            'data' => $data,
        ]);
    }





    public function uploadAttachment($id = null)
{
    if ($response = $this->authenticate()) {
        return $response;
    }

    if ($response = $this->requireFinance()) {
        return $response;
    }

    $contract = $this->findContract((string) $id, true);

    if (!$contract) {
        return $this->failNotFound('Credit contract not found.');
    }

    if ($contract['status'] !== 'DRAFT') {
        return $this->fail(
            'Attachments can only be changed on draft contracts.',
            409
        );
    }

    $file = $this->request->getFile('file');

    if (!$file || !$file->isValid() || $file->hasMoved()) {
        return $this->failValidationErrors(
            'A valid attachment file is required.'
        );
    }

    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    $mimeType = $file->getMimeType();

    if (!isset($allowedMimeTypes[$mimeType])) {
        return $this->failValidationErrors(
            'Only PDF, JPG, and PNG files are allowed.'
        );
    }

    if ($file->getSize() > 10 * 1024 * 1024) {
        return $this->failValidationErrors(
            'Attachment size cannot exceed 10 MB.'
        );
    }

    $directory = ROOTPATH . 'file/credit_contract/attachments/';

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        return $this->failServerError(
            'Unable to create attachment directory.'
        );
    }

    $attachmentId = $this->uuid();

    $storedFileName = $attachmentId . '.' . $allowedMimeTypes[$mimeType];

    $relativePath = 'credit_contract/attachments/' . $storedFileName;

    $absolutePath = $directory . $storedFileName;

    $this->db->transBegin();

    try {
        $file->move($directory, $storedFileName);

        if (!is_file($absolutePath)) {
            throw new \RuntimeException(
                'Uploaded attachment file could not be saved.'
            );
        }

        $this->db->table('credit_contract_attachments')->insert([
            'id' => $attachmentId,
            'contract_id' => $id,
            'attachment_type' => 'CONTRACT',
            'file_name' => $file->getClientName(),
            'stored_file_name' => $storedFileName,
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'uploaded_by' => $this->actorId(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $attachments = $this->jsonValue(
            $contract['attachments'] ?? []
        );

        if (!is_array($attachments)) {
            $attachments = [];
        }

        $attachments[] = $attachmentId;

        $this->db->table('credit_contracts')
            ->where('id', $id)
            ->update([
                'attachments' => json_encode(
                    array_values(array_unique($attachments))
                ),
                'updated_at' => date('Y-m-d H:i:s'),
                'version' => ((int) $contract['version']) + 1,
            ]);

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException(
                'Attachment database operation failed.'
            );
        }

        $this->db->transCommit();

        return $this->respondCreated([
            'success' => true,
            'message' => 'Attachment uploaded successfully.',
            'data' => [
                'id' => $attachmentId,
                'fileName' => $file->getClientName(),
                'mimeType' => $mimeType,
                'fileSize' => $file->getSize(),
                'attachmentType' => 'CONTRACT',
                'fileUrl' => '/api/credit-contracts/' .
                    $id . '/attachments/' . $attachmentId,
            ],
        ]);
    } catch (Throwable $e) {
        $this->db->transRollback();

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $this->logError(
            'Credit contract attachment upload failed',
            $e
        );

        return $this->failServerError(
            'Unable to upload attachment.'
        );
    }
}




    
public function showAttachment($id = null, $attachmentId = null)
{
    if ($response = $this->authenticate()) {
        return $response;
    }

    if ($response = $this->requireSalesOrFinance()) {
        return $response;
    }

    if (
        empty($id) ||
        empty($attachmentId) ||
        $id === 'undefined' ||
        $attachmentId === 'undefined'
    ) {
        return $this->failNotFound('Invalid attachment reference.');
    }

    $contract = $this->findContract((string) $id);

    if (!$contract) {
        return $this->failNotFound('Credit contract not found.');
    }

    $attachment = $this->db
        ->table('credit_contract_attachments')
        ->where('id', $attachmentId)
        ->where('contract_id', $id)
        ->where('attachment_type', 'CONTRACT')
        ->get()
        ->getRowArray();

    if (!$attachment) {
        return $this->failNotFound('Attachment not found.');
    }

    $expectedPrefix = 'credit_contract/attachments/';
    $relativePath = str_replace('\\', '/', $attachment['file_path']);

    if (strpos($relativePath, $expectedPrefix) !== 0) {
        return $this->failNotFound('Attachment file not found.');
    }

    $absolutePath = ROOTPATH . 'file/' . $relativePath;

    if (!is_file($absolutePath)) {
        return $this->failNotFound('Attachment file not found.');
    }

    $allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    $mimeType = strtolower(trim((string) $attachment['mime_type']));

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        return $this->fail(
            'This attachment type cannot be displayed.',
            415
        );
    }

    return $this->response
        ->download($absolutePath, null)
        ->setFileName($attachment['file_name'])
        ->setContentType($mimeType);
}


    private function streamCreditAttachment(string $attachmentId)
    {
        $row = $this->db->table('attachments')
            ->where('id', $attachmentId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->failNotFound('Attachment not found.');
        }

        $storageKey = (string) ($row['storage_key'] ?? '');

        if (
            !str_starts_with($storageKey, self::ATTACHMENT_DIRECTORY . '/') &&
            !str_starts_with($storageKey, self::PAYMENT_DIRECTORY . '/') &&
            !str_starts_with($storageKey, self::WITHHOLDING_DIRECTORY . '/')
        ) {
            return $this->failNotFound('Attachment not found.');
        }

        $path = ROOTPATH . $storageKey;

        if (!is_file($path)) {
            return $this->failNotFound('Attachment file not found.');
        }

        return $this->response
            ->setHeader('Content-Type', $row['mime_type'])
            ->setHeader(
                'Content-Disposition',
                'inline; filename="' . addslashes($row['file_name']) . '"'
            )
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody(file_get_contents($path));
    }

    public function deleteAttachment($id = null, $attachmentId = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $this->db->transBegin();

        $absolutePath = null;

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();

                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'DRAFT') {
                $this->db->transRollback();

                return $this->fail(
                    'Attachments cannot be removed after activation.',
                    409
                );
            }

            $attachment = $this->db->table('credit_contract_attachments')
                ->where('id', $attachmentId)
                ->where('contract_id', $id)
                ->where('attachment_type', 'CONTRACT')
                ->get()
                ->getRowArray();

            if (!$attachment) {
                $this->db->transRollback();

                return $this->failNotFound('Attachment not found.');
            }

            $relativePath = str_replace(
                '\\',
                '/',
                $attachment['file_path']
            );

            $expectedPrefix = 'credit_contract/attachments/';

            if (strpos($relativePath, $expectedPrefix) !== 0) {
                throw new \RuntimeException(
                    'Invalid attachment storage path.'
                );
            }

            $absolutePath = ROOTPATH . 'file/' . $relativePath;

            $attachments = $this->jsonValue(
                $contract['attachments'] ?? []
            );

            $attachments = array_values(array_filter(
                $attachments,
                static function ($item) use ($attachmentId) {
                    return (string) $item !== (string) $attachmentId;
                }
            ));

            $this->db->table('credit_contracts')
                ->where('id', $id)
                ->update([
                    'attachments' => json_encode($attachments),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'version' => ((int) $contract['version']) + 1,
                ]);

            $this->db->table('credit_contract_attachments')
                ->where('id', $attachmentId)
                ->where('contract_id', $id)
                ->delete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Attachment deletion failed.'
                );
            }

            $this->db->transCommit();

            if ($absolutePath && is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Attachment deleted successfully.',
                'data' => $attachments,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            $this->logError(
                'Credit contract attachment delete failed',
                $e
            );

            return $this->failServerError(
                'Unable to delete attachment.'
            );
        }
    }

    /* ============================================================
     * GET /api/credit-contracts
     * ============================================================ */

    public function index()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if (!$this->isFinance() && !$this->isSales()) {
            return $this->failForbidden(
                'You are not authorized to view credit contracts.'
            );
        }

        try {
            $builder = $this->db->table('credit_contracts');

            if (!$this->isFinance()) {
                $builder->where('status', 'ACTIVE');
            }

            $rows = $builder->orderBy('created_at', 'DESC')
                ->get()
                ->getResultArray();

            return $this->respond([
                'success' => true,
                'data' => array_map(
                    fn ($row) => $this->mapContract($row),
                    $rows
                ),
            ]);
        } catch (Throwable $e) {
            $this->logError('Credit contract list failed', $e);
            return $this->failServerError(
                'Unable to retrieve credit contracts.'
            );
        }
    }

    public function show($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        try {
            $contract = $this->findContract((string) $id);

            if (!$contract) {
                return $this->failNotFound('Credit contract not found.');
            }

            if (!$this->isFinance() && $contract['status'] !== 'ACTIVE') {
                return $this->failNotFound('Credit contract not found.');
            }

            return $this->respond([
                'success' => true,
                'data' => $this->mapContract($contract, true),
            ]);
        } catch (Throwable $e) {
            $this->logError('Credit contract show failed', $e);
            return $this->failServerError(
                'Unable to retrieve credit contract.'
            );
        }
    }

    /* ============================================================
     * CREATE CONTRACT
     * ============================================================ */

    public function create()
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $input = $this->input();

        $customerName = trim((string) ($input['customerName'] ?? ''));
        $tin = trim((string) ($input['tin'] ?? ''));
        $contractValue = $this->amount($input['contractValue'] ?? 0);
        $contractDate = $this->toDate($input['contractDate'] ?? null);

        if ($customerName === '' || $tin === '') {
            return $this->failValidationErrors(
                'Customer name and TIN are required.'
            );
        }

        if ($contractValue === null || $contractValue < 0) {
            return $this->failValidationErrors(
                'Contract value cannot be negative.'
            );
        }

        if ($contractDate === null) {
            return $this->failValidationErrors(
                'A valid contract date is required.'
            );
        }

        $attachments = $input['attachments'] ?? [];

        if (!is_array($attachments)) {
            return $this->failValidationErrors(
                'Attachments must be an array.'
            );
        }

        $this->db->transBegin();

        try {
            $id = $this->uuid();
            $now = date('Y-m-d H:i:s');

            $this->db->table('credit_contracts')->insert([
                'id' => $id,
                'customer_name' => $customerName,
                'customer_tin' => $tin,
                'contract_value' => $contractValue,
                'fs_no' => trim((string) ($input['fsNo'] ?? '')) ?: null,
                'contract_date' => $contractDate,
                'remark' => trim((string) ($input['remark'] ?? '')) ?: null,
                'attachments' => json_encode([]),
                'status' => 'DRAFT',
                'created_by' => $this->actorId(),
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]);

            $this->addStatusHistory(
                $id,
                null,
                'DRAFT',
                'CREATED',
                'Credit contract created.'
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Credit contract creation failed.'
                );
            }

            $this->db->transCommit();

            return $this->respondCreated([
                'success' => true,
                'message' => 'Credit contract created successfully.',
                'data' => $this->mapContract($this->findContract($id)),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            $this->logError(
                'Credit contract create failed',
                $e
            );

            return $this->failServerError(
                'Unable to create credit contract.'
            );
        }
    }

    public function update($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $input = $this->input();
        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'DRAFT') {
                $this->db->transRollback();
                return $this->fail(
                    'Only draft credit contracts can be edited.',
                    409
                );
            }

            $customerName = trim((string) ($input['customerName'] ?? ''));
            $tin = trim((string) ($input['tin'] ?? ''));
            $contractValue = $this->amount($input['contractValue'] ?? null);
            $contractDate = $this->toDate($input['contractDate'] ?? null);

            if (
                $customerName === '' ||
                $tin === '' ||
                $contractValue === null ||
                $contractValue <= 0 ||
                $contractDate === null
            ) {
                $this->db->transRollback();
                return $this->failValidationErrors(
                    'Customer name, TIN, valid contract value and contract date are required.'
                );
            }

            $attachments = $input['attachments']
                ?? $this->jsonValue($contract['attachments'] ?? []);

            if (!is_array($attachments)) {
                $this->db->transRollback();
                return $this->failValidationErrors(
                    'Attachments must be an array.'
                );
            }

            foreach ($attachments as $attachment) {
                if (
                    !is_array($attachment) ||
                    !$this->attachmentMimeAllowed($attachment['id'] ?? null)
                ) {
                    $this->db->transRollback();
                    return $this->failValidationErrors(
                        'A contract attachment is invalid or unsupported.'
                    );
                }
            }

            $this->db->table('credit_contracts')
                ->where('id', $id)
                ->update([
                    'customer_name' => $customerName,
                    'customer_tin' => $tin,
                    'contract_value' => $contractValue,
                    'fs_no' => trim((string) ($input['fsNo'] ?? '')) ?: null,
                    'contract_date' => $contractDate,
                    'remark' => trim((string) ($input['remark'] ?? '')) ?: null,
                    'attachments' => json_encode($attachments),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'version' => ((int) $contract['version']) + 1,
                ]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Credit contract update failed.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Credit contract updated successfully.',
                'data' => $this->mapContract($this->findContract((string) $id)),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract update failed', $e);

            return $this->failServerError(
                'Unable to update credit contract.'
            );
        }
    }

    public function activate($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'DRAFT') {
                $this->db->transRollback();
                return $this->fail(
                    'Only draft credit contracts can be activated.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('credit_contracts')
                ->where('id', $id)
                ->where('status', 'DRAFT')
                ->update([
                    'status' => 'ACTIVE',
                    'activated_by' => $this->actorId(),
                    'activated_at' => $now,
                    'updated_at' => $now,
                    'version' => ((int) $contract['version']) + 1,
                ]);

            if ($this->db->affectedRows() !== 1) {
                $this->db->transRollback();
                return $this->fail(
                    'Contract status changed. Refresh and try again.',
                    409
                );
            }

            $this->addStatusHistory(
                (string) $id,
                'DRAFT',
                'ACTIVE',
                'ACTIVATED'
            );

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Credit contract activated successfully.',
                'data' => $this->mapContract($this->findContract((string) $id)),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract activation failed', $e);

            return $this->failServerError(
                'Unable to activate credit contract.'
            );
        }
    }

    public function cancel($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $reason = trim((string) ($this->input()['reason'] ?? ''));

        if ($reason === '') {
            return $this->failValidationErrors(
                'A cancellation reason is required.'
            );
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if (in_array($contract['status'], ['INVOICED', 'CANCELLED'], true)) {
                $this->db->transRollback();
                return $this->fail(
                    'This credit contract cannot be cancelled.',
                    409
                );
            }

            $previous = $contract['status'];
            $now = date('Y-m-d H:i:s');

            $this->db->table('credit_contracts')
                ->where('id', $id)
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_by' => $this->actorId(),
                    'cancelled_at' => $now,
                    'cancellation_reason' => $reason,
                    'updated_at' => $now,
                    'version' => ((int) $contract['version']) + 1,
                ]);

            $this->addStatusHistory(
                (string) $id,
                $previous,
                'CANCELLED',
                'CANCELLED',
                $reason
            );

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Credit contract cancelled.',
                'data' => $this->mapContract($this->findContract((string) $id)),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract cancellation failed', $e);

            return $this->failServerError(
                'Unable to cancel credit contract.'
            );
        }
    }

    /* ============================================================
     * ORDERS
     * ============================================================ */

    public function orders($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        $contract = $this->findContract((string) $id);

        if (!$contract) {
            return $this->failNotFound('Credit contract not found.');
        }

        if (!$this->isFinance() && $contract['status'] !== 'ACTIVE') {
            return $this->failNotFound('Credit contract not found.');
        }

        return $this->respond([
            'success' => true,
            'data' => $this->getOrders((string) $id),
        ]);
    }

    public function createOrder($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        $input = $this->input();

        $quantity = is_numeric($input['quantity'] ?? null)
            ? (float) $input['quantity']
            : 0;

        $unitPrice = $this->amount($input['unitPrice'] ?? null);
        $aggregate = trim((string) ($input['aggregate'] ?? ''));
        $orderDate = $this->toDate($input['orderDate'] ?? null);

        if (
            !in_array($aggregate, ['00', '01', '02'], true) ||
            !is_finite($quantity) ||
            $quantity <= 0 ||
            $unitPrice === null ||
            $orderDate === null
        ) {
            return $this->failValidationErrors(
                'Aggregate, positive quantity, valid unit price and order date are required.'
            );
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'ACTIVE') {
                $this->db->transRollback();
                return $this->fail(
                    'Orders can only be added to active credit contracts.',
                    409
                );
            }

            $orderId = $this->uuid();
            $orderNumber = $this->nextOrderNumber((string) $id);
            $lineTotal = round($quantity * $unitPrice, 2);
            $now = date('Y-m-d H:i:s');

            $this->db->table('credit_contract_orders')->insert([
                'id' => $orderId,
                'contract_id' => $id,
                'order_number' => $orderNumber,
                'aggregate' => $aggregate,
                'quantity' => $quantity,
                'unit' => trim((string) ($input['unit'] ?? 'm³')) ?: 'm³',
                'unit_price' => $unitPrice,
                'line_total_excl_vat' => $lineTotal,
                'royalty_invoice_number' =>
                    trim((string) ($input['royaltyInvoiceNumber'] ?? '')) ?: null,
                'order_date' => $orderDate,
                'status' => 'COMPLETED',
                'completed_by' => $this->actorId(),
                'royalty_source_key' => 'credit-contract-order:' . $orderId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            if (!$order) {
                throw new \RuntimeException(
                    'Unable to retrieve the saved credit order.'
                );
            }

            // Create one individual Sales Invoice for this order.
            $invoice = $this->createOrderInvoice($contract, $order);

            $this->db->table('credit_contract_order_history')->insert([
                'id' => $this->uuid(),
                'contract_id' => $id,
                'order_id' => $orderId,
                'action' => 'ORDER_COMPLETED',
                'old_values' => null,
                'new_values' => json_encode([
                    'orderNumber' => $orderNumber,
                    'aggregate' => $aggregate,
                    'quantity' => $quantity,
                    'unitPrice' => $unitPrice,
                    'lineTotalExclVat' => $lineTotal,
                    'salesInvoiceId' => $invoice['id'],
                    'salesInvoiceNumber' => $invoice['invoice_number'],
                ]),
                'actor_id' => $this->actorId(),
                'created_at' => $now,
            ]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Unable to save credit contract order and Sales Invoice.'
                );
            }

            $this->db->transCommit();

            $order = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            return $this->respondCreated([
                'success' => true,
                'message' => 'Credit contract order and its Sales Invoice were created.',
                'data' => $this->mapOrder($order),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract order create failed', $e);

            return $this->failServerError(
                'Unable to add credit contract order.'
            );
        }
    }

    public function updateOrder($id = null, $orderId = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        $input = $this->input();
        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'ACTIVE') {
                $this->db->transRollback();
                return $this->fail(
                    'Orders cannot be edited after the contract is completed.',
                    409
                );
            }

            $order = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            if (!$order) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract order not found.');
            }

            if ($order['status'] !== 'COMPLETED') {
                $this->db->transRollback();
                return $this->fail(
                    'Only completed orders can be edited.',
                    409
                );
            }

            if (
                !$this->isFinance() &&
                ($order['completed_by'] ?? null) !== $this->actorId()
            ) {
                $this->db->transRollback();
                return $this->failForbidden(
                    'Only the salesperson who completed this order or Finance can edit it.'
                );
            }

            $quantity = is_numeric($input['quantity'] ?? null)
                ? (float) $input['quantity']
                : 0;

            $unitPrice = $this->amount($input['unitPrice'] ?? null);
            $aggregate = trim((string) ($input['aggregate'] ?? ''));
            $orderDate = $this->toDate($input['orderDate'] ?? null);

            if (
                !in_array($aggregate, ['00', '01', '02'], true) ||
                !is_finite($quantity) ||
                $quantity <= 0 ||
                $unitPrice === null ||
                $orderDate === null
            ) {
                $this->db->transRollback();
                return $this->failValidationErrors(
                    'Aggregate, positive quantity, valid unit price and order date are required.'
                );
            }

            $lineTotal = round($quantity * $unitPrice, 2);
            $now = date('Y-m-d H:i:s');

            $oldValues = [
                'aggregate' => $order['aggregate'],
                'quantity' => (float) $order['quantity'],
                'unitPrice' => (float) $order['unit_price'],
                'orderDate' => $order['order_date'],
            ];

            $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->update([
                    'aggregate' => $aggregate,
                    'quantity' => $quantity,
                    'unit' => trim((string) ($input['unit'] ?? $order['unit'])) ?: 'm³',
                    'unit_price' => $unitPrice,
                    'line_total_excl_vat' => $lineTotal,
                    'royalty_invoice_number' =>
                        trim((string) ($input['royaltyInvoiceNumber'] ?? $order['royalty_invoice_number'])) ?: null,
                    'order_date' => $orderDate,
                    'last_edited_by' => $this->actorId(),
                    'edited_at' => $now,
                    'updated_at' => $now,
                ]);

            $updatedOrder = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            // Keep the existing individual Sales Invoice synchronized.
            $this->updateOrderInvoice($updatedOrder);

            $this->db->table('credit_contract_order_history')->insert([
                'id' => $this->uuid(),
                'contract_id' => $id,
                'order_id' => $orderId,
                'action' => 'ORDER_EDITED',
                'old_values' => json_encode($oldValues),
                'new_values' => json_encode([
                    'aggregate' => $aggregate,
                    'quantity' => $quantity,
                    'unitPrice' => $unitPrice,
                    'orderDate' => $orderDate,
                ]),
                'actor_id' => $this->actorId(),
                'created_at' => $now,
            ]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Credit contract order update failed.'
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Credit contract order and its Sales Invoice updated.',
                'data' => $this->mapOrder($updatedOrder),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract order update failed', $e);

            return $this->failServerError(
                'Unable to update credit contract order.'
            );
        }
    }

    public function cancelOrder($id = null, $orderId = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $reason = trim((string) ($this->input()['reason'] ?? ''));

        if ($reason === '') {
            return $this->failValidationErrors(
                'A cancellation reason is required.'
            );
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] !== 'ACTIVE') {
                $this->db->transRollback();
                return $this->fail(
                    'Orders cannot be cancelled after contract completion.',
                    409
                );
            }

            $order = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            if (!$order) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract order not found.');
            }

            if ($order['status'] === 'CANCELLED') {
                $this->db->transRollback();
                return $this->fail(
                    'This order is already cancelled.',
                    409
                );
            }

            $invoice = $this->invoiceForOrder((string) $orderId);

            if (
                $invoice &&
                !in_array($invoice['status'], ['DRAFT', 'REJECTED'], true)
            ) {
                $this->db->transRollback();
                return $this->fail(
                    'This order cannot be cancelled because its Sales Invoice has entered the approval workflow.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_by' => $this->actorId(),
                    'cancelled_at' => $now,
                    'cancellation_reason' => $reason,
                    'updated_at' => $now,
                ]);

            if ($invoice) {
                $this->db->table('sales_invoices')
                    ->where('id', $invoice['id'])
                    ->whereIn('status', ['DRAFT', 'REJECTED'])
                    ->delete();
            }

            $this->db->table('credit_contract_order_history')->insert([
                'id' => $this->uuid(),
                'contract_id' => $id,
                'order_id' => $orderId,
                'action' => 'ORDER_CANCELLED',
                'old_values' => json_encode(['status' => $order['status']]),
                'new_values' => json_encode([
                    'status' => 'CANCELLED',
                    'reason' => $reason,
                ]),
                'actor_id' => $this->actorId(),
                'created_at' => $now,
            ]);

            $this->db->transCommit();

            $updated = $this->db->table('credit_contract_orders')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            return $this->respond([
                'success' => true,
                'message' => 'Credit contract order cancelled.',
                'data' => $this->mapOrder($updated),
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit contract order cancellation failed', $e);

            return $this->failServerError(
                'Unable to cancel credit contract order.'
            );
        }
    }

    /* ============================================================
     * PAYMENT INFORMATION
     * ============================================================ */

    public function getPaymentInformation($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        if (!$this->findContract((string) $id)) {
            return $this->failNotFound('Credit contract not found.');
        }

        $payment = $this->db->table('credit_contract_payment_information')
            ->where('contract_id', $id)
            ->get()
            ->getRowArray();

        if (!$payment) {
            return $this->respond([
                'success' => true,
                'data' => null,
            ]);
        }

        return $this->respond([
            'success' => true,
            'data' => [
                'bank' => $payment['bank'],
                'paymentSender' => $payment['payment_sender'],
                'paymentAmount' => (float) $payment['payment_amount'],
                'paymentDate' => $payment['payment_date'],
                'paymentReferenceNumber' => $payment['payment_reference_number'],
                'paymentEvidence' => $this->attachmentDetails(
                    $payment['evidence_attachment_id']
                ),
                'remark' => $payment['remark'],
                'recordedBy' => $payment['recorded_by'],
                'recordedAt' => $payment['recorded_at'],
                'updatedAt' => $payment['updated_at'],
                'confirmed' => false,
            ],
        ]);
    }

    public function savePaymentInformation($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $input = $this->input();

        $bank = trim((string) ($input['bank'] ?? ''));
        $sender = trim((string) ($input['paymentSender'] ?? ''));
        $reference = trim((string) ($input['paymentReferenceNumber'] ?? ''));
        $paymentAmount = $this->amount($input['paymentAmount'] ?? null);
        $paymentDate = !empty($input['paymentDate'])
            ? $this->toDate($input['paymentDate'])
            : null;

        if (
            $bank === '' ||
            $sender === '' ||
            $reference === '' ||
            $paymentAmount === null ||
            (!empty($input['paymentDate']) && $paymentDate === null)
        ) {
            return $this->failValidationErrors(
                'Bank, payment sender, payment reference and a valid non-negative amount are required.'
            );
        }

        $evidence = $input['paymentEvidence'] ?? null;
        $evidenceId = $this->attachmentId($evidence);

        if (
            is_array($evidence) &&
            !empty($evidence['id']) &&
            ($evidenceId === null || !$this->attachmentMimeAllowed($evidenceId))
        ) {
            return $this->failValidationErrors(
                'Payment evidence must be a valid PDF, JPG or PNG attachment.'
            );
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if (in_array($contract['status'], ['CANCELLED', 'INVOICED'], true)) {
                $this->db->transRollback();
                return $this->fail(
                    'Payment information cannot be changed on this contract.',
                    409
                );
            }

            $existing = $this->db->table('credit_contract_payment_information')
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            $now = date('Y-m-d H:i:s');

            $data = [
                'bank' => $bank,
                'payment_sender' => $sender,
                'payment_amount' => $paymentAmount,
                'payment_date' => $paymentDate,
                'payment_reference_number' => $reference,
                'evidence_attachment_id' => $evidenceId,
                'remark' => trim((string) ($input['remark'] ?? '')) ?: null,
                'updated_at' => $now,
            ];

            if ($existing) {
                $this->db->table('credit_contract_payment_information')
                    ->where('contract_id', $id)
                    ->update($data);

                $action = 'PAYMENT_INFORMATION_UPDATED';
            } else {
                $data['id'] = $this->uuid();
                $data['contract_id'] = $id;
                $data['recorded_by'] = $this->actorId();
                $data['recorded_at'] = $now;

                $this->db->table('credit_contract_payment_information')
                    ->insert($data);

                $action = 'PAYMENT_INFORMATION_RECORDED';
            }

            $this->addFinancialHistory(
                (string) $id,
                $action,
                $bank . ' · ' . $reference . ' · not confirmed',
                $existing,
                $data
            );

            $this->db->transCommit();

            return $this->getPaymentInformation($id);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit payment information save failed', $e);

            return $this->failServerError(
                'Unable to save payment information.'
            );
        }
    }

    public function deletePaymentInformation($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if ($contract['status'] === 'INVOICED') {
                $this->db->transRollback();
                return $this->fail(
                    'Payment information cannot be cleared after completion.',
                    409
                );
            }

            $existing = $this->db->table('credit_contract_payment_information')
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            if ($existing) {
                $this->db->table('credit_contract_payment_information')
                    ->where('contract_id', $id)
                    ->delete();

                $this->addFinancialHistory(
                    (string) $id,
                    'PAYMENT_INFORMATION_CLEARED',
                    $existing['bank'] . ' · ' . $existing['payment_reference_number'],
                    $existing,
                    null
                );
            }

            $this->db->transCommit();

            return $this->respond([
                'success' => true,
                'message' => 'Payment information cleared.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit payment information delete failed', $e);

            return $this->failServerError(
                'Unable to clear payment information.'
            );
        }
    }

    /* ============================================================
     * WITHHOLDING INFORMATION
     * ============================================================ */

    public function getWithholding($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        if (!$this->findContract((string) $id)) {
            return $this->failNotFound('Credit contract not found.');
        }

        $row = $this->db->table('credit_contract_withholding_information')
            ->where('contract_id', $id)
            ->get()
            ->getRowArray();

        return $this->respond([
            'success' => true,
            'data' => [
                'enabled' => $row ? (bool) $row['enabled'] : false,
                'rate' => $row ? (float) $row['rate'] : 0,
                'evidence' => $row
                    ? $this->attachmentDetails($row['evidence_attachment_id'])
                    : null,
                'recordedBy' => $row['recorded_by'] ?? null,
                'recordedAt' => $row['recorded_at'] ?? null,
                'updatedAt' => $row['updated_at'] ?? null,
            ],
        ]);
    }

    public function saveWithholding($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireFinance()) {
            return $response;
        }

        $input = $this->input();

        $enabled = filter_var(
            $input['enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $evidenceId = $enabled
            ? $this->attachmentId($input['evidence'] ?? null)
            : null;

        if (
            $enabled &&
            (
                $evidenceId === null ||
                !$this->attachmentMimeAllowed($evidenceId)
            )
        ) {
            return $this->failValidationErrors(
                'Withholding evidence is required and must be PDF, JPG or PNG.'
            );
        }

        $this->db->transBegin();

        try {
            $contract = $this->findContract((string) $id, true);

            if (!$contract) {
                $this->db->transRollback();
                return $this->failNotFound('Credit contract not found.');
            }

            if (in_array($contract['status'], ['CANCELLED', 'INVOICED'], true)) {
                $this->db->transRollback();
                return $this->fail(
                    'Withholding cannot be changed on this contract.',
                    409
                );
            }

            $existing = $this->db->table('credit_contract_withholding_information')
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            $now = date('Y-m-d H:i:s');

            $data = [
                'enabled' => $enabled,
                'rate' => $enabled ? self::WITHHOLDING_RATE : 0,
                'evidence_attachment_id' => $evidenceId,
                'updated_at' => $now,
            ];

            if ($existing) {
                $this->db->table('credit_contract_withholding_information')
                    ->where('contract_id', $id)
                    ->update($data);
            } else {
                $data['id'] = $this->uuid();
                $data['contract_id'] = $id;
                $data['recorded_by'] = $this->actorId();
                $data['recorded_at'] = $now;

                $this->db->table('credit_contract_withholding_information')
                    ->insert($data);
            }

            $this->addFinancialHistory(
                (string) $id,
                'WITHHOLDING_UPDATED',
                $enabled ? 'Withholding enabled at 3%.' : 'Withholding disabled.',
                $existing,
                $data
            );

            $this->db->transCommit();

            return $this->getWithholding($id);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->logError('Credit withholding save failed', $e);

            return $this->failServerError(
                'Unable to save withholding information.'
            );
        }
    }

    /* ============================================================
     * FINANCIAL REPORT
     * ============================================================ */

    public function financialReport($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        try {
            $contract = $this->findContract((string) $id);

            if (!$contract) {
                return $this->failNotFound('Credit contract not found.');
            }

            $orders = $this->getOrders((string) $id);

            $completed = array_values(array_filter(
                $orders,
                static fn ($order) => $order['status'] === 'COMPLETED'
            ));

            $subtotal = 0.0;

            foreach ($completed as $order) {
                $subtotal += (float) $order['lineTotalExclVat'];
            }

            $subtotal = round($subtotal, 2);
            $vat = round($subtotal * self::VAT_RATE, 2);
            $gross = round($subtotal + $vat, 2);

            $withholding = $this->db->table('credit_contract_withholding_information')
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            $withholdingEnabled = $withholding
                ? (bool) $withholding['enabled']
                : false;

            $withholdingRate = $withholdingEnabled ? self::WITHHOLDING_RATE : 0;
            $withholdingAmount = round($gross * $withholdingRate, 2);
            $net = round($gross - $withholdingAmount, 2);

            $payment = $this->db->table('credit_contract_payment_information')
                ->where('contract_id', $id)
                ->get()
                ->getRowArray();

            $invoices = $this->invoicesForContract((string) $id);

            $amountPaid = 0.0;
            $paymentStatus = 'NO_PAYMENT_INFORMATION';

            if ($invoices) {
                $statuses = array_column($invoices, 'status');

                if (
                    count(array_filter(
                        $statuses,
                        static fn ($status) => $status === 'PAYMENT_CONFIRMED'
                    )) === count($invoices)
                ) {
                    $amountPaid = $net;
                    $paymentStatus = 'PAID';
                } elseif (in_array('FINANCE_APPROVED', $statuses, true)) {
                    $paymentStatus = 'AWAITING_PAYMENT_CONFIRMATION';
                } elseif (
                    in_array('DRAFT', $statuses, true) ||
                    in_array('REJECTED', $statuses, true)
                ) {
                    $paymentStatus = 'AWAITING_INVOICE_APPROVAL';
                } else {
                    $paymentStatus = 'AWAITING_INVOICE_APPROVAL';
                }
            } elseif ($payment) {
                $paymentStatus = 'RECORDED_NOT_CONFIRMED';
            }

            $fulfilled = $gross;
            $contractValue = (float) $contract['contract_value'];

            $rawPercent = $contractValue > 0
                ? ($fulfilled / $contractValue) * 100
                : 0;

            $report = [
                'contractId' => $contract['id'],
                'contractNumber' => $contract['contract_number'],
                'customerName' => $contract['customer_name'],
                'customerTin' => $contract['customer_tin'],
                'contractDate' => $contract['contract_date'],
                'completionDate' => $contract['finalized_at'],
                'fsNo' => $contract['fs_no'] ?? '',
                'contractValue' => $contractValue,
                'fulfilledValue' => $fulfilled,
                'remainingContractValue' => max(
                    0,
                    round($contractValue - $fulfilled, 2)
                ),
                'fulfillmentPercent' => min(100, $rawPercent),
                'rawFulfillmentPercent' => $rawPercent,
                'overFulfilled' => $fulfilled > $contractValue,
                'grossAmountDue' => $gross,
                'totalVat' => $vat,
                'subtotalExclVat' => $subtotal,
                'withholdingEnabled' => $withholdingEnabled,
                'withholdingRate' => $withholdingRate,
                'withholdingAmount' => $withholdingAmount,
                'netAmount' => $net,
                'amountPaid' => $amountPaid,
                'outstandingBalance' => max(0, $net - $amountPaid),
                'paymentStatus' => $paymentStatus,
                'withholdingStatus' => $withholdingEnabled
                    ? 'APPLIED'
                    : 'NOT_APPLIED',
                'invoice' => $invoices ? array_map(
                    static fn ($invoice) => [
                        'id' => $invoice['id'],
                        'invoiceNumber' => $invoice['invoice_number'],
                        'status' => $invoice['status'],
                        'paymentConfirmed' => $invoice['status'] === 'PAYMENT_CONFIRMED',
                    ],
                    $invoices
                ) : [],
                'payment' => $payment ? [
                    'bank' => $payment['bank'],
                    'paymentSender' => $payment['payment_sender'],
                    'paymentAmount' => (float) $payment['payment_amount'],
                    'paymentDate' => $payment['payment_date'],
                    'paymentReferenceNumber' => $payment['payment_reference_number'],
                    'recordedBy' => $payment['recorded_by'],
                    'recordedAt' => $payment['recorded_at'],
                    'evidence' => $this->attachmentDetails(
                        $payment['evidence_attachment_id']
                    ),
                ] : null,
                'withholding' => [
                    'enabled' => $withholdingEnabled,
                    'rate' => $withholdingRate,
                    'evidence' => $withholding
                        ? $this->attachmentDetails(
                            $withholding['evidence_attachment_id']
                        )
                        : null,
                ],
                'orders' => $completed,
            ];

            return $this->respond([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Throwable $e) {
            $this->logError('Credit financial report failed', $e);

            return $this->failServerError(
                'Unable to generate credit contract financial report.'
            );
        }
    }

    /* ============================================================
     * GET /api/credit-contracts/{id}/invoice
     * Returns all individual invoices belonging to the contract.
     * ============================================================ */

    public function invoice($id = null)
    {
        if ($response = $this->authenticate()) {
            return $response;
        }

        if ($response = $this->requireSalesOrFinance()) {
            return $response;
        }

        if (!$this->findContract((string) $id)) {
            return $this->failNotFound('Credit contract not found.');
        }

        $invoices = $this->invoicesForContract((string) $id);

        return $this->respond([
            'success' => true,
            'data' => array_map(
                static fn ($invoice) => [
                    'id' => $invoice['id'],
                    'invoiceNumber' => $invoice['invoice_number'],
                    'status' => $invoice['status'],
                    'fsNo' => $invoice['fs_no'],
                ],
                $invoices
            ),
        ]);
    }

    /* ============================================================
     * COMPLETE CREDIT CONTRACT
     *
     * Does not create a consolidated invoice.
     * Assigns FS No to the existing individual invoices.
     * Saves payment and withholding evidence.
     * Changes contract status to INVOICED (completed).
     * ============================================================ */

    
public function complete($id = null)
{
    if ($response = $this->authenticate()) {
        return $response;
    }

    if ($response = $this->requireFinance()) {
        return $response;
    }

    $input = $this->input();

    $fsNo = trim((string) ($input['fsNo'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));

    $bank = trim((string) ($input['bank'] ?? ''));
    $sender = trim((string) ($input['paymentSender'] ?? ''));
    $reference = trim((string) ($input['paymentReferenceNumber'] ?? ''));

    $paymentAmount = $this->amount($input['paymentAmount'] ?? null);
    $paymentDate = $this->toDate($input['paymentDate'] ?? null);

    $withholdingEnabled = filter_var(
        $input['withholdingEnabled'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    if ($fsNo === '') {
        return $this->failValidationErrors(
            'FS No is required to complete a credit contract.'
        );
    }

    if (
        $bank === '' ||
        $sender === '' ||
        $reference === '' ||
        $paymentAmount === null ||
        $paymentDate === null
    ) {
        return $this->failValidationErrors(
            'Bank, payment sender, payment reference, payment amount and payment date are required.'
        );
    }

    $this->db->transBegin();

    try {
        $contract = $this->findContract((string) $id, true);

        if (!$contract) {
            $this->db->transRollback();

            return $this->failNotFound(
                'Credit contract not found.'
            );
        }

        if ($contract['status'] === 'INVOICED') {
            $this->db->transRollback();

            return $this->fail(
                'This credit contract has already been completed.',
                409
            );
        }

        if ($contract['status'] !== 'ACTIVE') {
            $this->db->transRollback();

            return $this->fail(
                'Only active credit contracts can be completed.',
                409
            );
        }

        $orders = $this->db->table('credit_contract_orders')
            ->where('contract_id', $id)
            ->where('status', 'COMPLETED')
            ->orderBy('order_number', 'ASC')
            ->get()
            ->getResultArray();

        if (!$orders) {
            $this->db->transRollback();

            return $this->fail(
                'At least one completed order is required.',
                409
            );
        }

        $fulfilled = 0.0;

        foreach ($orders as $order) {
            $fulfilled += (float) $order['line_total_excl_vat'];
        }

        $fulfilled = round(
            $fulfilled * (1 + self::VAT_RATE),
            2
        );

        $belowValue = $fulfilled < (float) $contract['contract_value'];

        if ($belowValue && $reason === '') {
            $this->db->transRollback();

            return $this->failValidationErrors(
                'A reason is required when completing below the contract value.'
            );
        }

        $minimumPayment = $fulfilled;

        if ($paymentAmount < $minimumPayment) {
            $this->db->transRollback();

            return $this->failValidationErrors(
                'Payment amount cannot be less than the total completed order value including VAT.'
            );
        }

        $invoices = $this->invoicesForContract((string) $id);

        if (count($invoices) !== count($orders)) {
            $this->db->transRollback();

            return $this->fail(
                'Every completed credit order must have its own Sales Invoice before the contract can be completed.',
                409
            );
        }

        /*
         * Do not complete a credit contract while any individual
         * Sales Invoice is still waiting for Finance review.
         *
         * Finance must approve those invoices first.
         */
        $pendingInvoices = [];

        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'PENDING_FINANCE_REVIEW') {
                $pendingInvoices[] = $invoice['invoice_number'] ?? $invoice['id'];
            }
        }

        if (!empty($pendingInvoices)) {
            $this->db->transRollback();

            return $this->fail(
                'This credit contract has pending Sales Invoices that must be approved by Finance before completion. Pending invoices: ' .
                implode(', ', $pendingInvoices),
                409
            );
        }

        /*
         * At this point, pending invoices have been excluded.
         * Other existing statuses are allowed. Their statuses
         * will be preserved when assigning the FS No.
         */

        $paymentEvidence = $this->uploadCreditFile(
            'paymentEvidence',
            self::PAYMENT_DIRECTORY
        );

        if (!$paymentEvidence) {
            $this->db->transRollback();

            return $this->failValidationErrors(
                'Payment evidence is required.'
            );
        }

        $withholdingEvidence = null;

        if ($withholdingEnabled) {
            $withholdingEvidence = $this->uploadCreditFile(
                'withholdingEvidence',
                self::WITHHOLDING_DIRECTORY
            );

            if (!$withholdingEvidence) {
                $this->db->transRollback();

                return $this->failValidationErrors(
                    'Withholding evidence is required when withholding is enabled.'
                );
            }
        }

        $paymentEvidenceId = $paymentEvidence['id'];
        $withholdingEvidenceId = $withholdingEvidence['id'] ?? null;

        $withholdingRate = $withholdingEnabled
            ? self::WITHHOLDING_RATE
            : 0;

        $withholdingAmount = round(
            $fulfilled * $withholdingRate,
            2
        );

        $netAmount = round(
            $fulfilled - $withholdingAmount,
            2
        );

        $now = date('Y-m-d H:i:s');

        /*
         * Assign the same FS No to every existing individual
         * Sales Invoice without changing its current status.
         */
        foreach ($invoices as $invoice) {
            $invoiceGross = (float) $invoice['total_price_incl_vat'];

            $invoiceWithholding = round(
                $invoiceGross * $withholdingRate,
                2
            );

            $this->db->table('sales_invoices')
                ->where('id', $invoice['id'])
                ->update([
                    'fs_no' => $fsNo,
                    'withholding_enabled' => $withholdingEnabled,
                    'withholding_rate' => $withholdingRate,
                    'withholding_amount' => $invoiceWithholding,
                    'net_amount_after_withholding' => round(
                        $invoiceGross - $invoiceWithholding,
                        2
                    ),
                    'payment_evidence_attachment_id' => $paymentEvidenceId,
                    'withholding_evidence_attachment_id' => $withholdingEvidenceId,
                    'bank' => $bank,
                    'payment_sender' => $sender,
                    'payment_reference_number' => $reference,
                    'updated_at' => $now,
                    'version' => ((int) ($invoice['version'] ?? 1)) + 1,
                ]);
        }

        // Save payment information.
        $existingPayment = $this->db->table('credit_contract_payment_information')
            ->where('contract_id', $id)
            ->get()
            ->getRowArray();

        $paymentData = [
            'bank' => $bank,
            'payment_sender' => $sender,
            'payment_amount' => $paymentAmount,
            'payment_date' => $paymentDate,
            'payment_reference_number' => $reference,
            'evidence_attachment_id' => $paymentEvidenceId,
            'remark' => trim((string) ($input['remark'] ?? '')) ?: null,
            'updated_at' => $now,
        ];

        if ($existingPayment) {
            $this->db->table('credit_contract_payment_information')
                ->where('contract_id', $id)
                ->update($paymentData);
        } else {
            $paymentData['id'] = $this->uuid();
            $paymentData['contract_id'] = $id;
            $paymentData['recorded_by'] = $this->actorId();
            $paymentData['recorded_at'] = $now;

            $this->db->table('credit_contract_payment_information')
                ->insert($paymentData);
        }

        // Save withholding information.
        $existingWithholding = $this->db->table('credit_contract_withholding_information')
            ->where('contract_id', $id)
            ->get()
            ->getRowArray();

        $withholdingData = [
            'enabled' => $withholdingEnabled,
            'rate' => $withholdingRate,
            'evidence_attachment_id' => $withholdingEvidenceId,
            'updated_at' => $now,
        ];

        if ($existingWithholding) {
            $this->db->table('credit_contract_withholding_information')
                ->where('contract_id', $id)
                ->update($withholdingData);
        } else {
            $withholdingData['id'] = $this->uuid();
            $withholdingData['contract_id'] = $id;
            $withholdingData['recorded_by'] = $this->actorId();
            $withholdingData['recorded_at'] = $now;

            $this->db->table('credit_contract_withholding_information')
                ->insert($withholdingData);
        }

        // Complete and freeze the credit contract.
        $this->db->table('credit_contracts')
            ->where('id', $id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'INVOICED',
                'fs_no' => $fsNo,
                'finalized_by' => $this->actorId(),
                'finalized_at' => $now,
                'finalization_reason' => $reason ?: null,
                'updated_at' => $now,
                'version' => ((int) $contract['version']) + 1,
            ]);

        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException(
                'Contract status could not be changed.'
            );
        }

        $this->addStatusHistory(
            (string) $id,
            'ACTIVE',
            'INVOICED',
            'FINALIZED',
            $reason ?: null
        );

        $this->addFinancialHistory(
            (string) $id,
            'CREDIT_CONTRACT_COMPLETED',
            'Credit contract completed. FS No: ' . $fsNo,
            null,
            [
                'fsNo' => $fsNo,
                'paymentAmount' => $paymentAmount,
                'paymentDate' => $paymentDate,
                'withholdingEnabled' => $withholdingEnabled,
                'withholdingAmount' => $withholdingAmount,
                'netAmount' => $netAmount,
                'invoiceIds' => array_column($invoices, 'id'),
            ]
        );

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException(
                'Credit contract completion failed.'
            );
        }

        $this->db->transCommit();

        return $this->respond([
            'success' => true,
            'message' => 'Credit contract completed. All existing individual Sales Invoices were updated with the FS No.',
            'data' => [
                'contractId' => $id,
                'status' => 'INVOICED',
                'fsNo' => $fsNo,
                'fulfilledValue' => $fulfilled,
                'paymentAmount' => $paymentAmount,
                'withholdingEnabled' => $withholdingEnabled,
                'withholdingAmount' => $withholdingAmount,
                'netAmount' => $netAmount,
                'invoiceIds' => array_column($invoices, 'id'),
                'invoiceNumbers' => array_column($invoices, 'invoice_number'),
                'closedBelowValue' => $belowValue,
            ],
        ]);
    } catch (Throwable $e) {
        $this->db->transRollback();

        $this->logError(
            'Credit contract completion failed',
            $e
        );

        return $this->failServerError(
            'Unable to complete credit contract.'
        );
    }
}


    /**
     * Backward-compatible endpoint for the existing /finalize route.
     */
    public function finalize($id = null)
    {
        return $this->complete($id);
    }
}