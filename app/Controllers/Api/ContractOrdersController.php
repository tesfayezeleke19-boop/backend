<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class ContractOrdersController extends ResourceController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    private const VAT_RATE = 0.15;
    private const ROYALTY_RATE = 0.03;

    private const CONTRACT_STATUSES = [
        'DRAFT',
        'ACTIVE',
        'COMPLETED',
    ];

    public function __construct()
    {
        $this->db = db_connect();
    }

    /* =========================================================
       RESPONSE AND AUTHENTICATION
    ========================================================= */

    private function respondSuccess($data = null, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function respondError(string $message, int $status = 400)
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => false,
            'message' => $message,
        ]);
    }

    private function actor(): ?array
    {
        if ($this->authenticatedUser !== null) {
            return $this->authenticatedUser;
        }

        $header = $this->request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        if ($token === '') {
            return null;
        }

        $session = $this->db->table('user_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if (!$session) {
            return null;
        }

        $user = $this->db->table('users')
            ->select('id, role, email, account_status')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (!$user || strtoupper((string) $user['account_status']) !== 'ACTIVE') {
            return null;
        }

        $this->db->table('user_sessions')
            ->where('id', $session['id'])
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);

        $this->authenticatedUser = $user;

        return $user;
    }

    private function requireActor()
    {
        $actor = $this->actor();

        if (!$actor) {
            return $this->respondError('Unauthorized. Please sign in again.', 401);
        }

        return $actor;
    }

    private function requireRoles(array $roles)
    {
        $actor = $this->requireActor();

        if ($actor instanceof ResponseInterface) {
            return $actor;
        }

        if (!in_array(strtoupper((string) $actor['role']), $roles, true)) {
            return $this->respondError('You do not have permission to perform this action.', 403);
        }

        return $actor;
    }

    private function isFinanceRole(): bool
    {
        $actor = $this->actor();

        return $actor && in_array(
            strtoupper((string) $actor['role']),
            ['FINANCE', 'ADMIN'],
            true
        );
    }

    private function isSalesRole(): bool
    {
        $actor = $this->actor();

        return $actor && in_array(
            strtoupper((string) $actor['role']),
            ['SALES', 'ADMIN'],
            true
        );
    }

    private function requestData(): array
    {
        $contentType = strtolower(
            (string) $this->request->getHeaderLine('Content-Type')
        );

        if (str_contains($contentType, 'multipart/form-data')) {
            $data = $this->request->getPost();

            if (!is_array($data)) {
                return [];
            }

            foreach (['payment', 'withholding'] as $field) {
                if (
                    isset($data[$field]) &&
                    is_string($data[$field]) &&
                    trim($data[$field]) !== ''
                ) {
                    $decoded = json_decode($data[$field], true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \CodeIgniter\Exceptions\PageNotFoundException(
                            "Invalid JSON supplied for {$field}: " .
                            json_last_error_msg()
                        );
                    }

                    $data[$field] = $decoded;
                }
            }

            return $data;
        }

        if (str_contains($contentType, 'application/json')) {
            $body = $this->request->getBody();

            if (trim($body) === '') {
                return [];
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw \CodeIgniter\HTTP\Exceptions\HTTPException::forInvalidJSON(
                    json_last_error_msg()
                );
            }

            return is_array($decoded) ? $decoded : [];
        }

        $data = $this->request->getPost();

        return is_array($data) ? $data : [];
    }

    private function validUuid($value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) === 1;
    }

    private function dateValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $timestamp = (int) floor(((float) $value) / 1000);

        $date = new \DateTimeImmutable('@' . $timestamp);

        return $date
            ->setTimezone(new \DateTimeZone('Africa/Addis_Ababa'))
            ->format('Y-m-d');
    }

    $date = \DateTime::createFromFormat('!Y-m-d', (string) $value);

    if (!$date || $date->format('Y-m-d') !== (string) $value) {
        return null;
    }

    return $date->format('Y-m-d');
}

    private function dateToEpoch($value): ?int
    {
        if (!$value) {
            return null;
        }

        $timestamp = strtotime((string) $value . ' 12:00:00 UTC');

        return $timestamp === false ? null : $timestamp * 1000;
    }

    private function number($value): float
    {
        return round((float) $value, 2);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function nextSalesInvoiceNumber(): string
    {
        $this->db->query('SELECT pg_advisory_xact_lock(734281)');
        $row = $this->db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_number FROM 5) AS BIGINT)), 0) AS last_number FROM sales_invoices WHERE invoice_number ~ '^INV-[0-9]+$'")->getRowArray();
        return 'INV-' . str_pad((string) (((int) ($row['last_number'] ?? 0)) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function orderGrossValue(float $quantity, float $unitPrice): float
    {
        $subtotal = round($quantity * $unitPrice, 2);
        return round($subtotal + round($subtotal * self::VAT_RATE, 2), 2);
    }

    private function committedContractValue(string $contractId, ?string $excludeOrderId = null): float
    {
        $builder = $this->db->table('contract_orders')
            ->where('contract_id', $contractId)
            ->whereIn('status', ['PENDING', 'COMPLETED', 'PAYMENT_CONFIRMED']);
        if ($excludeOrderId !== null) $builder->where('id !=', $excludeOrderId);
        $rows = $builder->get()->getResultArray();
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->orderGrossValue((float) $row['quantity'], (float) $row['unit_price']);
        }
        return round($total, 2);
    }

    private function createOrderInvoice(array $contract, array $order): string
{
    $invoiceId = $this->uuid();
    $lineId = $this->uuid();

    $subtotal = round(
        (float) $order['quantity'] * (float) $order['unit_price'],
        2
    );

    $vat = round($subtotal * self::VAT_RATE, 2);
    $total = round($subtotal + $vat, 2);

    $financial = $this->db->table('contract_financial_information')
        ->where('contract_id', $contract['id'])
        ->get()
        ->getRowArray() ?? [];

    $withholdingEnabled = !empty($financial['withholding_enabled']);

    $withholdingRate = $withholdingEnabled
        ? (float) ($financial['withholding_rate'] ?? self::ROYALTY_RATE)
        : 0.0;

    $withholdingAmount = round($total * $withholdingRate, 2);

    $now = date('Y-m-d H:i:s');
    $createdBy = $order['created_by'] ?? null;

    $this->db->table('sales_invoices')->insert([
        'id' => $invoiceId,
        'invoice_number' => $this->nextSalesInvoiceNumber(),

        'customer_name_snapshot' =>
            (string) $contract['customer_name_snapshot'],

        'customer_tin_snapshot' =>
            (string) $contract['customer_tin_snapshot'],

        'fs_no' => (string) $contract['fs_no'],

        'contract_id' => (string) $contract['id'],
        'is_contract' => true,

        'invoice_date' => $order['order_date'],

        // Contract-order invoices enter the Finance approval queue immediately.
        'status' => 'PENDING_FINANCE_REVIEW',

        'subtotal_excl_vat' => $subtotal,
        'vat_rate' => self::VAT_RATE,
        'total_vat' => $vat,
        'total_price_incl_vat' => $total,

        'withholding_enabled' => $withholdingEnabled,
        'withholding_rate' => $withholdingRate,
        'withholding_amount' => $withholdingAmount,

        'net_amount_after_withholding' => round(
            $total - $withholdingAmount,
            2
        ),

        'bank' => $financial['payment_bank'] ?? null,
        'payment_sender' => $financial['payment_sender'] ?? null,
        'payment_reference_number' =>
            $financial['payment_reference_number'] ?? null,

        // Contract evidence IDs belong to contract_attachments, not attachments.
        'payment_evidence_attachment_id' => null,
        'withholding_evidence_attachment_id' => null,

        'created_by' => $createdBy,
        'submitted_by' => $createdBy,
        'submitted_at' => $now,

        'created_at' => $now,
        'updated_at' => $now,
        'version' => 1,
    ]);

    $this->db->table('sales_invoice_lines')->insert([
        'id' => $lineId,
        'invoice_id' => $invoiceId,

        'order_number' => (int) $order['order_number'],
        'aggregate' => (string) $order['aggregate'],

        'quantity' => (float) $order['quantity'],
        'unit' => 'm³',
        'unit_price' => (float) $order['unit_price'],

        'line_total_excl_vat' => $subtotal,

        'royalty_order_number' => (int) $order['order_number'],
        'royalty_invoice_number' =>
            $order['royalty_invoice_number'] ?? null,

        'contract_order_id' => (string) $order['id'],
        'order_date' => $order['order_date'],

        'royalty_amount' => round(
            $subtotal * self::ROYALTY_RATE,
            2
        ),
    ]);

    $this->db->table('contract_orders')
        ->where('id', $order['id'])
        ->update([
            'sales_invoice_line_id' => $lineId,
            'updated_at' => $now,
        ]);

    return $invoiceId;
}

    private function linkedInvoiceForOrder(array $order): ?array
    {
        if (empty($order['sales_invoice_line_id'])) return null;
        return $this->db->table('sales_invoice_lines l')
            ->select('i.id AS invoice_id, i.invoice_number, i.status AS invoice_status, l.id AS line_id')
            ->join('sales_invoices i', 'i.id = l.invoice_id')
            ->where('l.id', $order['sales_invoice_line_id'])
            ->get()->getRowArray();
    }

    private function getContract(string $id): ?array
    {
        return $this->db->table('contracts')
            ->where('id', $id)
            ->get()
            ->getRowArray();
    }

    private function getOrder(string $id): ?array
    {
        return $this->db->table('contract_orders')
            ->where('id', $id)
            ->get()
            ->getRowArray();
    }

    private function isContractCompleted(array $contract): bool
    {
        return strtoupper((string) ($contract['status'] ?? '')) === 'COMPLETED';
    }

    private function canAcceptOrders(array $contract): bool
    {
        return strtoupper((string) ($contract['status'] ?? '')) === 'ACTIVE';
    }

    /* =========================================================
       HISTORY
    ========================================================= */

    private function recordContractStatus(
        string $contractId,
        string $status,
        ?string $note = null
    ): void {
        $actor = $this->actor();

        $this->db->table('contract_status_history')->insert([
            'contract_id' => $contractId,
            'status' => $status,
            'changed_by' => $actor['id'] ?? null,
            'note' => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function recordFinancialHistory(
        string $contractId,
        string $action,
        ?array $before,
        ?array $after,
        ?string $note = null
    ): void {
        $actor = $this->actor();

        $this->db->table('contract_financial_history')->insert([
            'contract_id' => $contractId,
            'action' => $action,
            'changed_by' => $actor['id'] ?? null,
            'note' => $note,
            'before_data' => $before
                ? json_encode($before, JSON_UNESCAPED_UNICODE)
                : null,
            'after_data' => $after
                ? json_encode($after, JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* =========================================================
       CONTRACT NUMBER AND MAPPERS
    ========================================================= */

    private function nextContractNumber(): string
    {
        $this->db->query("SELECT pg_advisory_xact_lock(734921, 1)");

        $row = $this->db->query("
            SELECT COALESCE(
                MAX(
                    CAST(
                        SUBSTRING(contract_number FROM '^CONTRACT-([0-9]+)$')
                        AS BIGINT
                    )
                ),
                0
            ) AS last_number
            FROM contracts
            WHERE contract_number ~ '^CONTRACT-[0-9]+$'
        ")->getRowArray();

        $next = ((int) ($row['last_number'] ?? 0)) + 1;

        return 'CONTRACT-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function mapAttachment(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'fileName' => (string) $row['file_name'],
            'fileUrl' => '/api/contracts/attachments/' . (string) $row['id'],
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'fileSize' => (int) ($row['file_size'] ?? 0),
        ];
    }

    private function attachmentsFor(string $contractId, string $type = 'CONTRACT'): array
    {
        $rows = $this->db->table('contract_attachments')
            ->where('contract_id', $contractId)
            ->where('attachment_type', $type)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn($row) => $this->mapAttachment($row), $rows);
    }

    private function mapContract(array $row): array
    {
        $id = (string) $row['id'];

        $statusHistory = $this->db->table('contract_status_history h')
            ->select('h.status, h.note, h.created_at, h.changed_by, u.email AS changed_by_email')
            ->join('users u', 'u.id = h.changed_by', 'left')
            ->where('h.contract_id', $id)
            ->orderBy('h.created_at', 'ASC')
            ->get()
            ->getResultArray();

        $financial = $this->getFinancialInformation($id);

        $createdBy = null;
        if (!empty($row['created_by'])) {
            $createdBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['created_by'])
                ->get()
                ->getRowArray();
        }

        $activatedBy = null;
        if (!empty($row['activated_by'])) {
            $activatedBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['activated_by'])
                ->get()
                ->getRowArray();
        }

        $completionBy = null;
        if (!empty($row['completion_confirmed_by'])) {
            $completionBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['completion_confirmed_by'])
                ->get()
                ->getRowArray();
        }

        $cancelledBy = null;
        if (!empty($row['cancelled_by'])) {
            $cancelledBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['cancelled_by'])
                ->get()
                ->getRowArray();
        }

        $convertedBy = null;
        if (!empty($row['converted_by'])) {
            $convertedBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['converted_by'])
                ->get()
                ->getRowArray();
        }

        return [
            'id' => $id,
            'contractNumber' => $row['contract_number'],
            'customer' => [
                'name' => $row['customer_name_snapshot'],
                'tin' => $row['customer_tin_snapshot'],
            ],
            'contractValue' => (float) $row['contract_value'],
            'fsNo' => $row['fs_no'],
            'contractDate' => $this->dateToEpoch($row['contract_date']),
            'attachments' => $this->attachmentsFor($id, 'CONTRACT'),
            'remark' => $row['remark'],
            'status' => $row['status'],
            'createdBy' => $createdBy['email'] ?? null,
            'createdByUserId' => $createdBy['id'] ?? $row['created_by'],
            'createdAt' => strtotime($row['created_at']) * 1000,
            'updatedAt' => strtotime($row['updated_at']) * 1000,
            'activatedAt' => !empty($row['activated_at'])
                ? strtotime($row['activated_at']) * 1000 : null,
            'activatedBy' => $activatedBy['email'] ?? null,
            'readyAt' => !empty($row['ready_at'])
                ? strtotime($row['ready_at']) * 1000 : null,
            'completionConfirmedAt' => !empty($row['completion_confirmed_at'])
                ? strtotime($row['completion_confirmed_at']) * 1000 : null,
            'completionConfirmedBy' => $completionBy['email'] ?? null,
            'completionReason' => $row['completion_reason'],
            'closedBelowValue' => (bool) $row['closed_below_value'],
            'cancelledAt' => !empty($row['cancelled_at'])
                ? strtotime($row['cancelled_at']) * 1000 : null,
            'cancelledBy' => $cancelledBy['email'] ?? null,
            'cancellationReason' => $row['cancellation_reason'],
            'salesInvoiceId' => $row['sales_invoice_id'],
            'salesInvoiceNumber' => null,
            'convertedAt' => !empty($row['converted_at'])
                ? strtotime($row['converted_at']) * 1000 : null,
            'convertedBy' => $convertedBy['email'] ?? null,
            'conversionStartedAt' => !empty($row['conversion_started_at'])
                ? strtotime($row['conversion_started_at']) * 1000 : null,
            'conversionError' => $row['conversion_error'],
            'payment' => $financial['payment'] ?? null,
            'withholding' => $financial['withholding'] ?? [
                'enabled' => false,
                'rate' => self::ROYALTY_RATE,
                'evidence' => null,
            ],
            'paymentHistory' => $financial['paymentHistory'] ?? [],
            'statusHistory' => array_map(static function ($history) {
                return [
                    'status' => $history['status'],
                    'note' => $history['note'],
                    'changedBy' => $history['changed_by_email'],
                    'createdAt' => strtotime($history['created_at']) * 1000,
                ];
            }, $statusHistory),
        ];
    }

    private function mapOrder(array $row): array
    {
        $createdBy = null;
        if (!empty($row['created_by'])) {
            $createdBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['created_by'])
                ->get()
                ->getRowArray();
        }

        $completedBy = null;
        if (!empty($row['completed_by'])) {
            $completedBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['completed_by'])
                ->get()
                ->getRowArray();
        }

        $cancelledBy = null;
        if (!empty($row['cancelled_by'])) {
            $cancelledBy = $this->db->table('users')
                ->select('id, email')
                ->where('id', $row['cancelled_by'])
                ->get()
                ->getRowArray();
        }

        $subtotal = round((float) $row['quantity'] * (float) $row['unit_price'], 2);
        $vat = round($subtotal * self::VAT_RATE, 2);

        $linkedInvoice = $this->linkedInvoiceForOrder($row);

        // No edit-history records are created or displayed.
        $edit = null;

        return [
            'id' => (string) $row['id'],
            'contractId' => (string) $row['contract_id'],
            'orderNumber' => (int) $row['order_number'],
            'aggregate' => (string) $row['aggregate'],
            'quantity' => (float) $row['quantity'],
            'unit' => 'm³',
            'unitPrice' => (float) $row['unit_price'],
            'lineTotalExclVat' => round($subtotal, 2),
            'lineTotalInclVat' => round($subtotal + $vat, 2),
            'royaltyInvoiceNumber' => $row['royalty_invoice_number'],
            'orderDate' => $this->dateToEpoch($row['order_date']),
            'status' => $row['status'],
            'salesInvoiceId' => $linkedInvoice['invoice_id'] ?? null,
            'salesInvoiceNumber' => $linkedInvoice['invoice_number'] ?? null,
            'salesInvoiceStatus' => $linkedInvoice['invoice_status'] ?? null,
            'completedByUserId' => $completedBy['id'] ?? $row['completed_by'],
            'completedByName' => $completedBy['email'] ?? null,
            'completedByEmail' => $completedBy['email'] ?? null,
            'completedAt' => !empty($row['completed_at'])
                ? strtotime($row['completed_at']) * 1000 : null,
            'createdByUserId' => $createdBy['id'] ?? $row['created_by'],
            'createdByName' => $createdBy['email'] ?? null,
            'createdAt' => strtotime($row['created_at']) * 1000,
            'updatedAt' => strtotime($row['updated_at']) * 1000,
            'lastEditedByUserId' => $edit['changed_by'] ?? null,
            'lastEditedByName' => $edit['email'] ?? null,
            'lastEditedByEmail' => $edit['email'] ?? null,
            'editedAt' => !empty($edit['created_at'])
                ? strtotime($edit['created_at']) * 1000 : null,
            'cancelledAt' => !empty($row['cancelled_at'])
                ? strtotime($row['cancelled_at']) * 1000 : null,
            'cancelledBy' => $cancelledBy['email'] ?? null,
            'cancelReason' => $row['cancellation_reason'],
            'royaltySourceKey' => 'contract-order:' . $row['id'],
        ];
    }

    /* =========================================================
       CONTRACT LIST AND DETAIL
    ========================================================= */

    public function index()
{
    $auth = $this->requireActor();
    if ($auth instanceof ResponseInterface) {
        return $auth;
    }

    $builder = $this->db->table('contracts c')
        ->select('c.*')
        ->orderBy('c.created_at', 'DESC');

    $status = $this->request->getGet('status');
    $search = $this->request->getGet('search');

    // Accept only scalar query parameters.
    $status = is_string($status) ? trim($status) : '';
    $search = is_string($search) ? trim($search) : '';

    if (
        $status !== '' &&
        in_array($status, self::CONTRACT_STATUSES, true)
    ) {
        $builder->where('c.status', $status);
    }

    if ($search !== '') {
        $builder->groupStart()
            ->like('c.contract_number', $search)
            ->orLike('c.customer_name_snapshot', $search)
            ->orLike('c.customer_tin_snapshot', $search)
            ->orLike('c.fs_no', $search)
            ->groupEnd();
    }

    $rows = $builder->get()->getResultArray();

    return $this->respondSuccess(array_map(
        fn($row) => $this->mapContract($row),
        $rows
    ));
}
    public function show($id = null)
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->validUuid((string) $id)) {
            return $this->respondError('Invalid contract ID.', 422);
        }

        $contract = $this->getContract($id);

        if (!$contract) {
            return $this->respondError('Contract not found.', 404);
        }

        return $this->respondSuccess($this->mapContract($contract));
    }

    public function create()
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $data = $this->requestData();

        foreach (['customer', 'payment', 'withholding'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data[$field] = $decoded;
                }
            }
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : null;
        $withholding = is_array($data['withholding'] ?? null) ? $data['withholding'] : [];

        $customerName = trim((string) ($data['customerName'] ?? $customer['name'] ?? ''));
        $customerTin = trim((string) ($data['customerTin'] ?? $customer['tin'] ?? ''));
        $fsNo = trim((string) ($data['fsNo'] ?? ''));
        $contractValue = (float) ($data['contractValue'] ?? 0);
        $contractDate = $this->dateValue($data['contractDate'] ?? null);

        if ($customerName === '' || $customerTin === '' || $fsNo === '') {
            return $this->respondError('Customer name, TIN and FS number are required.', 422);
        }

        if ($contractValue <= 0 || !$contractDate) {
            return $this->respondError('Enter a valid contract value and contract date.', 422);
        }

        if (!$payment) {
            return $this->respondError('Initial payment information is required.', 422);
        }

        $paymentBank = trim((string) ($payment['bank'] ?? ''));
        $paymentSender = trim((string) ($payment['paymentSender'] ?? ''));
        $paymentAmount = (float) ($payment['paymentAmount'] ?? 0);
        $paymentDate = $this->dateValue($payment['paymentDate'] ?? null);
        $paymentReference = trim((string) ($payment['paymentReferenceNumber'] ?? ''));

        if ($paymentBank === '' || $paymentSender === '' || $paymentReference === '' || !$paymentDate) {
            return $this->respondError('Payment bank, sender, payment date and reference number are required.', 422);
        }

        if ($paymentAmount < $contractValue) {
            return $this->respondError('Initial payment amount cannot be less than the contract value.', 422);
        }

        $withholdingEnabled = (bool) ($withholding['enabled'] ?? false);
        $withholdingRate = (float) ($withholding['rate'] ?? self::ROYALTY_RATE);

        if ($withholdingRate < 0 || $withholdingRate > 1) {
            return $this->respondError('Withholding rate must be between 0 and 1.', 422);
        }

        $contractFile = $this->request->getFile('contractAttachment');
        $paymentFile = $this->request->getFile('paymentAttachment');
        $withholdingFile = $this->request->getFile('withholdingAttachment');

        if ($withholdingEnabled && (!$withholdingFile || !$withholdingFile->isValid())) {
            return $this->respondError('Withholding evidence is required when withholding is enabled.', 422);
        }

        $this->db->transBegin();
        $storedFiles = [];

        try {
            $now = date('Y-m-d H:i:s');
            $contractNumber = $this->nextContractNumber();

            $this->db->table('contracts')->insert([
                'contract_number' => $contractNumber,
                'customer_id' => $data['customerId'] ?? null,
                'customer_name_snapshot' => $customerName,
                'customer_tin_snapshot' => $customerTin,
                'contract_value' => $contractValue,
                'fs_no' => $fsNo,
                'contract_date' => $contractDate,
                'status' => 'DRAFT',
                'remark' => $data['remark'] ?? null,
                'created_by' => $auth['id'],
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]);

            $created = $this->db->table('contracts')
                ->where('contract_number', $contractNumber)
                ->get()
                ->getRowArray();

            if (!$created) {
                throw new \RuntimeException('Could not retrieve the newly created contract.');
            }

            $contractId = (string) $created['id'];

            $contractEvidence = $this->storeContractEvidence(
                $contractId,
                'CONTRACT',
                $contractFile,
                (string) $auth['id']
            );

            if ($contractEvidence) {
                $storedFiles[] = $contractEvidence['absolute_path'];
            }

            $paymentEvidence = $this->storeContractEvidence(
                $contractId,
                'PAYMENT',
                $paymentFile,
                (string) $auth['id']
            );

            if ($paymentEvidence) {
                $storedFiles[] = $paymentEvidence['absolute_path'];
            }

            $withholdingEvidence = $this->storeContractEvidence(
                $contractId,
                'WITHHOLDING',
                $withholdingFile,
                (string) $auth['id']
            );

            if ($withholdingEvidence) {
                $storedFiles[] = $withholdingEvidence['absolute_path'];
            }

            // Attachment metadata and financial evidence IDs are stored in
            // contract_attachments and contract_financial_information only.
            // No duplicate file paths are written to the contracts table.

            $this->db->table('contract_financial_information')->insert([
                'contract_id' => $contractId,
                'payment_bank' => $paymentBank,
                'payment_sender' => $paymentSender,
                'payment_amount' => $paymentAmount,
                'payment_date' => $paymentDate,
                'payment_reference_number' => $paymentReference,
                'payment_remark' => $payment['remark'] ?? null,
                'payment_evidence_id' => $paymentEvidence['id'] ?? null,
                'payment_recorded_by' => $auth['id'],
                'payment_recorded_at' => $now,
                'payment_updated_at' => $now,
                'withholding_enabled' => $withholdingEnabled,
                'withholding_rate' => $withholdingRate,
                'withholding_evidence_id' => $withholdingEvidence['id'] ?? null,
                'withholding_recorded_by' => $auth['id'],
                'withholding_recorded_at' => $now,
                'withholding_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordContractStatus($contractId, 'DRAFT', 'Contract created');

            $this->recordFinancialHistory(
                $contractId,
                'PAYMENT_INFORMATION_RECORDED',
                null,
                [
                    'bank' => $paymentBank,
                    'paymentSender' => $paymentSender,
                    'paymentAmount' => $paymentAmount,
                    'paymentDate' => $paymentDate,
                    'paymentReferenceNumber' => $paymentReference,
                ]
            );

            $this->recordFinancialHistory(
                $contractId,
                'WITHHOLDING_UPDATED',
                null,
                [
                    'enabled' => $withholdingEnabled,
                    'rate' => $withholdingRate,
                ]
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed while creating the contract.');
            }

            $this->db->transCommit();

            return $this->respondSuccess(
                $this->mapContract($this->getContract($contractId)),
                201
            );
        } catch (Throwable $e) {
            $this->db->transRollback();

            foreach ($storedFiles as $storedPath) {
                if (is_file($storedPath)) {
                    @unlink($storedPath);
                }
            }

            log_message('error', 'Contract create failed: ' . $e->getMessage());

            return $this->respondError(
                'Unable to create contract and save its financial information.',
                500
            );
        }
    }

    public function update($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->validUuid((string) $id)) {
            return $this->respondError('Invalid contract ID.', 422);
        }

        $contract = $this->getContract($id);

        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($contract['status'] !== 'DRAFT') {
            return $this->respondError('Only draft contracts can be edited.', 409);
        }

        $data = $this->requestData();
        $customer = $data['customer'] ?? [];

        $customerName = trim((string) ($data['customerName'] ?? $customer['name'] ?? $contract['customer_name_snapshot']));
        $customerTin = trim((string) ($data['customerTin'] ?? $customer['tin'] ?? $contract['customer_tin_snapshot']));
        $fsNo = trim((string) ($data['fsNo'] ?? $contract['fs_no']));
        $value = (float) ($data['contractValue'] ?? $contract['contract_value']);
        $date = $this->dateValue($data['contractDate'] ?? $contract['contract_date']);

        if ($customerName === '' || $customerTin === '' || $fsNo === '' || $value <= 0 || !$date) {
            return $this->respondError('Please provide valid contract information.', 422);
        }

        $this->db->table('contracts')->where('id', $id)->update([
            'customer_name_snapshot' => $customerName,
            'customer_tin_snapshot' => $customerTin,
            'customer_id' => $data['customerId'] ?? $contract['customer_id'],
            'contract_value' => $value,
            'fs_no' => $fsNo,
            'contract_date' => $date,
            'remark' => $data['remark'] ?? $contract['remark'],
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => ((int) $contract['version']) + 1,
        ]);

        return $this->respondSuccess($this->mapContract($this->getContract($id)));
    }

    /* =========================================================
       CONTRACT LIFECYCLE
    ========================================================= */

    public function activate($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $id);

        if (!$contract) return $this->respondError('Contract not found.', 404);
        if ($contract['status'] !== 'DRAFT') {
            return $this->respondError('Only draft contracts can be activated.', 409);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $this->db->table('contracts')->where('id', $id)->update([
                'status' => 'ACTIVE',
                'activated_at' => $now,
                'activated_by' => $auth['id'],
                'updated_at' => $now,
                'version' => ((int) $contract['version']) + 1,
            ]);

            $this->recordContractStatus((string) $id, 'ACTIVE', 'Contract activated');

            $this->db->transCommit();

            return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract activation failed: ' . $e->getMessage());
            return $this->respondError('Unable to activate contract.', 500);
        }
    }

    /**
     * Legacy endpoint retained for compatibility with old route definitions.
     * The lifecycle no longer has a READY_FOR_COMPLETION intermediate state.
     */
    public function requestCompletion($id = null)
    {
        return $this->respondError(
            'Completion review is no longer a separate contract status. Confirm completion directly.',
            410
        );
    }

    public function confirmCompletion($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $id);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($contract['status'] !== 'ACTIVE') {
            return $this->respondError('This contract cannot be confirmed as completed.', 409);
        }

        $data = $this->requestData();
        $reason = trim((string) ($data['reason'] ?? ''));
        $progress = $this->calculateProgress((string) $id);
        $below = $progress['fulfilledValue'] + 0.01 < (float) $contract['contract_value'];

        if ($below && $reason === '') {
            return $this->respondError('A reason is required when confirming completion below the contract value.', 422);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('contracts')->where('id', $id)->update([
            'status' => 'COMPLETED',
            'completion_confirmed_at' => $now,
            'completion_confirmed_by' => $auth['id'],
            'completion_reason' => $reason !== '' ? $reason : null,
            'closed_below_value' => $below,
            'updated_at' => $now,
        ]);

        $this->recordContractStatus(
            (string) $id,
            'COMPLETED',
            $below ? 'Completion confirmed below contract value: ' . $reason : 'Completion confirmed'
        );

        return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
    }

    /**
     * Contract cancellation has been removed from the contract lifecycle.
     * Individual contract orders can still be cancelled by Finance.
     */
    public function cancel($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        return $this->respondError(
            'Contract cancellation is not supported. Contracts can only be Draft, Active, or Completed.',
            409
        );
    }

    /* =========================================================
       ORDERS
    ========================================================= */

    public function allOrders()
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        $rows = $this->db->table('contract_orders')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respondSuccess(array_map(
            fn($row) => $this->mapOrder($row),
            $rows
        ));
    }

    public function orders($contractId = null)
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        $rows = $this->db->table('contract_orders')
            ->where('contract_id', $contractId)
            ->orderBy('order_number', 'ASC')
            ->get()
            ->getResultArray();

        return $this->respondSuccess(array_map(
            fn($row) => $this->mapOrder($row),
            $rows
        ));
    }

    public function createOrder($contractId = null)
    {
        $auth = $this->requireRoles(['SALES', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;
        $contractId = (string) $contractId;
        $contract = $this->getContract($contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);
        if (!$this->canAcceptOrders($contract)) return $this->respondError('This contract is not accepting orders.', 409);

        $data = $this->requestData();
        $aggregate = trim((string) ($data['aggregate'] ?? ''));
        $quantity = (float) ($data['quantity'] ?? 0);
        $royaltyInvoiceNumber = trim((string) ($data['royaltyInvoiceNumber'] ?? ''));
        $orderDate = $this->dateValue($data['orderDate'] ?? null);
        if ($aggregate === '' || !is_finite($quantity) || $quantity <= 0 || !$orderDate) {
            return $this->respondError('Aggregate, valid quantity and order date are required.', 422);
        }
        if ($royaltyInvoiceNumber === '') return $this->respondError('Royalty invoice number is required.', 422);

        $aggregateRow = $this->db->table('aggregate_types')->where('name', $aggregate)->where('is_active', true)->get()->getRowArray();
        if (!$aggregateRow) return $this->respondError('The selected aggregate type is unavailable.', 422);
        $unitPrice = round((float) $aggregateRow['price_per_m3'], 2);
        $newGross = $this->orderGrossValue($quantity, $unitPrice);
        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();
        try {
            // Serialize order creation for this contract to prevent overbooking.
            $lockedContract = $this->db->query('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId])->getRowArray();
            if (!$lockedContract || !$this->canAcceptOrders($lockedContract)) {
                throw new \DomainException('This contract is not accepting orders.');
            }
            $committed = $this->committedContractValue($contractId);
            $contractValue = round((float) $lockedContract['contract_value'], 2);
            if (round($committed + $newGross, 2) > $contractValue + 0.001) {
                throw new \DomainException('This order would exceed the remaining contract value. Pending and approved orders are included in this limit.');
            }

            $nextOrder = $this->db->table('contract_orders')->selectMax('order_number', 'last_number')->where('contract_id', $contractId)->get()->getRowArray();
            $orderNumber = ((int) ($nextOrder['last_number'] ?? 0)) + 1;
            $orderId = $this->uuid();
            $order = [
                'id' => $orderId,
                'contract_id' => $contractId,
                'order_number' => $orderNumber,
                'aggregate' => $aggregate,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'royalty_invoice_number' => $royaltyInvoiceNumber,
                'order_date' => $orderDate,
                'status' => 'PENDING',
                'created_by' => $auth['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->db->table('contract_orders')->insert($order);
            $this->createOrderInvoice($lockedContract, $order);

            if ($this->db->transStatus() === false) throw new \RuntimeException('Database transaction failed.');
            $this->db->transCommit();
            return $this->respondSuccess($this->mapOrder($this->getOrder($orderId)), 201);
        } catch (Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof \DomainException) return $this->respondError($e->getMessage(), 409);
            log_message('error', 'Contract order create failed: ' . $e->getMessage());
            return $this->respondError('Unable to create contract order and its sales invoice.', 500);
        }
    }

    public function updateOrder($orderId = null)
    {
        $auth = $this->requireRoles(['SALES', 'FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->validUuid((string) $orderId)) {
            return $this->respondError('Invalid order ID.', 422);
        }

        $order = $this->getOrder((string) $orderId);
        if (!$order) return $this->respondError('Order not found.', 404);

        $contract = $this->getContract((string) $order['contract_id']);
        if (!$contract) return $this->respondError('Contract not found.', 404);
        if ($this->isContractCompleted($contract)) {
            return $this->respondError('Orders cannot be changed after the contract is completed.', 409);
        }

        // Contract orders may be corrected regardless of order, contract,
        // or linked sales-invoice status. Normal sales invoices remain protected
        // by SalesInvoicesController::update().
        $linkedInvoice = $this->linkedInvoiceForOrder($order);

        if (strtoupper((string) $auth['role']) === 'SALES' &&
            (string) $order['created_by'] !== (string) $auth['id']) {
            return $this->respondError('Sales users can only edit their own orders.', 403);
        }

        $data = $this->requestData();

        $aggregate = trim((string) ($data['aggregate'] ?? $order['aggregate']));
        $quantity = (float) ($data['quantity'] ?? $order['quantity']);
        $royaltyInvoiceNumber = trim((string) ($data['royaltyInvoiceNumber'] ?? $order['royalty_invoice_number']));
        $orderDate = $this->dateValue($data['orderDate'] ?? $order['order_date']);

        if ($aggregate === '' || $quantity <= 0 || !$orderDate || $royaltyInvoiceNumber === '') {
            return $this->respondError('Please provide valid order information.', 422);
        }

        $aggregateRow = $this->db->table('aggregate_types')
            ->where('name', $aggregate)
            ->get()
            ->getRowArray();

        if (!$aggregateRow) {
            return $this->respondError('Aggregate type not found.', 422);
        }

        $unitPrice = $aggregate === $order['aggregate']
            ? (float) $order['unit_price']
            : (float) $aggregateRow['price_per_m3'];

        $this->db->transBegin();

        try {
            $lockedContract = $this->db->query('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [(string) $order['contract_id']])->getRowArray();
            $committed = $this->committedContractValue((string) $order['contract_id'], (string) $orderId);
            $updatedGross = $this->orderGrossValue($quantity, $unitPrice);
            if (round($committed + $updatedGross, 2) > round((float) $lockedContract['contract_value'], 2) + 0.001) {
                throw new \DomainException('This change would exceed the remaining contract value. Pending and approved orders are included in this limit.');
            }

            $this->db->table('contract_orders')->where('id', $orderId)->update([
                'aggregate' => $aggregate, 'quantity' => $quantity, 'unit_price' => $unitPrice,
                'royalty_invoice_number' => $royaltyInvoiceNumber, 'order_date' => $orderDate, 'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($linkedInvoice) {
                $subtotal = round($quantity * $unitPrice, 2);
                $vat = round($subtotal * self::VAT_RATE, 2);
                $total = round($subtotal + $vat, 2);
                $invoice = $this->db->table('sales_invoices')->where('id', $linkedInvoice['invoice_id'])->get()->getRowArray();
                if (!$invoice) {
                    throw new \RuntimeException('The linked sales invoice was not found.');
                }
                $withholdingRate = !empty($invoice['withholding_enabled']) ? (float) $invoice['withholding_rate'] : 0.0;
                $withholdingAmount = round($total * $withholdingRate, 2);
                $this->db->table('sales_invoice_lines')->where('id', $linkedInvoice['line_id'])->update([
                    'aggregate' => $aggregate, 'quantity' => $quantity, 'unit_price' => $unitPrice,
                    'line_total_excl_vat' => $subtotal, 'royalty_invoice_number' => $royaltyInvoiceNumber,
                    'order_date' => $orderDate, 'royalty_amount' => round($subtotal * self::ROYALTY_RATE, 2),
                ]);
                $this->db->table('sales_invoices')->where('id', $linkedInvoice['invoice_id'])->update([
                    'invoice_date' => $orderDate, 'subtotal_excl_vat' => $subtotal, 'total_vat' => $vat,
                    'total_price_incl_vat' => $total, 'withholding_amount' => $withholdingAmount,
                    'net_amount_after_withholding' => round($total - $withholdingAmount, 2),
                    'updated_at' => date('Y-m-d H:i:s'), 'version' => ((int) ($invoice['version'] ?? 1)) + 1,
                ]);
            }

            $this->db->transCommit();

            return $this->respondSuccess($this->mapOrder($this->getOrder((string) $orderId)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof \DomainException) return $this->respondError($e->getMessage(), 409);
            log_message('error', 'Contract order update failed: ' . $e->getMessage());
            return $this->respondError('Unable to update contract order.', 500);
        }
    }

    public function cancelOrder($orderId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->validUuid((string) $orderId)) {
            return $this->respondError('Invalid order ID.', 422);
        }

        $order = $this->getOrder((string) $orderId);
        if (!$order) return $this->respondError('Order not found.', 404);

        $contract = $this->getContract((string) $order['contract_id']);
        if (!$contract) return $this->respondError('Contract not found.', 404);
        if ($this->isContractCompleted($contract)) {
            return $this->respondError('Orders cannot be changed after the contract is completed.', 409);
        }

        $data = $this->requestData();
        $reason = trim((string) ($data['reason'] ?? $data['cancellationReason'] ?? ''));
        if ($reason === '') {
            return $this->respondError('Cancellation reason is required.', 422);
        }

        $this->db->transBegin();
        try {
            $lockedOrder = $this->db->query(
                'SELECT * FROM contract_orders WHERE id = ? FOR UPDATE',
                [(string) $orderId]
            )->getRowArray();
            if (!$lockedOrder) {
                throw new \DomainException('Order not found.');
            }

            $linkedInvoice = $this->linkedInvoiceForOrder($lockedOrder);
            if ($linkedInvoice) {
                // Contract order invoices are created as one-invoice/one-line.
                // Remove the linked line and invoice when no other lines remain.
                $this->db->table('contract_orders')
                    ->where('id', $orderId)
                    ->update(['sales_invoice_line_id' => null]);

                $this->db->table('sales_invoice_lines')
                    ->where('id', $linkedInvoice['line_id'])
                    ->delete();

                $remainingLines = $this->db->table('sales_invoice_lines')
                    ->where('invoice_id', $linkedInvoice['invoice_id'])
                    ->countAllResults();

                if ($remainingLines === 0) {
                    $this->db->table('sales_invoices')
                        ->where('id', $linkedInvoice['invoice_id'])
                        ->delete();
                } else {
                    $totals = $this->db->query(
                        'SELECT COALESCE(SUM(line_total_excl_vat), 0) AS subtotal FROM sales_invoice_lines WHERE invoice_id = ?',
                        [$linkedInvoice['invoice_id']]
                    )->getRowArray();
                    $invoice = $this->db->table('sales_invoices')
                        ->where('id', $linkedInvoice['invoice_id'])
                        ->get()->getRowArray();
                    $subtotal = round((float) ($totals['subtotal'] ?? 0), 2);
                    $vatRate = (float) ($invoice['vat_rate'] ?? self::VAT_RATE);
                    $vat = round($subtotal * $vatRate, 2);
                    $total = round($subtotal + $vat, 2);
                    $withholdingRate = !empty($invoice['withholding_enabled'])
                        ? (float) ($invoice['withholding_rate'] ?? 0) : 0.0;
                    $withholding = round($total * $withholdingRate, 2);
                    $this->db->table('sales_invoices')
                        ->where('id', $linkedInvoice['invoice_id'])
                        ->update([
                            'subtotal_excl_vat' => $subtotal,
                            'total_vat' => $vat,
                            'total_price_incl_vat' => $total,
                            'withholding_amount' => $withholding,
                            'net_amount_after_withholding' => round($total - $withholding, 2),
                            'updated_at' => date('Y-m-d H:i:s'),
                            'version' => ((int) ($invoice['version'] ?? 1)) + 1,
                        ]);
                }
            }

            // Cancellation means permanent removal of the contract order row.
            $this->db->table('contract_orders')->where('id', $orderId)->delete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }
            $this->db->transCommit();

            return $this->respondSuccess([
                'id' => (string) $orderId,
                'deleted' => true,
                'message' => 'Contract order deleted successfully.',
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof \DomainException) {
                return $this->respondError($e->getMessage(), 404);
            }
            log_message('error', 'Contract order deletion failed: ' . $e->getMessage());
            return $this->respondError('Unable to delete contract order.', 500);
        }
    }

    /* =========================================================
       PROGRESS AND READINESS
    ========================================================= */

    private function calculateProgress(string $contractId): array
    {
        $contract = $this->getContract($contractId);

        $orders = $this->db->table('contract_orders')
            ->where('contract_id', $contractId)
            ->get()
            ->getResultArray();

        $completed = array_filter($orders, static function ($order) {
            return in_array(strtoupper((string) $order['status']), ['COMPLETED', 'PAYMENT_CONFIRMED'], true);
        });

        $cancelled = array_filter($orders, static function ($order) {
            return strtoupper((string) $order['status']) === 'CANCELLED';
        });

        $subtotal = 0.0;

        foreach ($completed as $order) {
            $subtotal += round((float) $order['quantity'] * (float) $order['unit_price'], 2);
        }

        $subtotal = round($subtotal, 2);
        $vat = round($subtotal * self::VAT_RATE, 2);
        $fulfilledValue = round($subtotal + $vat, 2);
        $contractValue = (float) ($contract['contract_value'] ?? 0);

        return [
            'contractId' => $contractId,
            'contractValue' => round($contractValue, 2),
            'fulfilledSubtotal' => round($subtotal, 2),
            'vatAmount' => round($vat, 2),
            'fulfilledValue' => round($fulfilledValue, 2),
            'remainingValue' => round(max(0, $contractValue - $fulfilledValue), 2),
            'fulfillmentPercentage' => $contractValue > 0
                ? round(min(100, ($fulfilledValue / $contractValue) * 100), 2)
                : 0,
            'completedOrders' => count($completed),
            'cancelledOrders' => count($cancelled),
            'totalOrders' => count($orders),
            'royaltyAmount' => round($subtotal * self::ROYALTY_RATE, 2),
        ];
    }

    public function progress($contractId = null)
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->getContract((string) $contractId)) {
            return $this->respondError('Contract not found.', 404);
        }

        return $this->respondSuccess($this->calculateProgress((string) $contractId));
    }

    /* =========================================================
       ATTACHMENTS
    ========================================================= */

    /**
     * View or open a stored contract, payment, or withholding attachment.
     *
     * GET /api/contracts/attachments/{attachmentId}
     */
    

public function viewAttachment($id = null): ResponseInterface
{
    

    // 2. Validate UUID.
    if (!$this->validUuid($id)) {
        return $this->failNotFound('Attachment not found.');
    }

    // 3. Retrieve attachment metadata.
    $attachment = $this->db->table('contract_attachments')
        ->select('id, contract_id, attachment_type, file_path, stored_file_name, mime_type, file_name')
        ->where('id', $id)
        ->get()
        ->getRowArray();

    if (!$attachment) {
        return $this->failNotFound('Attachment not found.');
    }

    // 4. Validate storage path.
    $storagePath = str_replace('\\', '/', trim((string) ($attachment['file_path'] ?? '')));

    $allowedDirectories = [
        'file/contract/attechment/',
        'file/contract/payment/',
        'file/contract/witholding/',
    ];

    $validPath = false;

    foreach ($allowedDirectories as $directory) {
        if (stripos($storagePath, $directory) === 0) {
            $validPath = true;
            break;
        }
    }

    // Only allow the expected contract attachment directories and filenames.
    if (
        !$validPath ||
        strpos($storagePath, '..') !== false ||
        !preg_match(
            '/^file\/contract\/(attechment|payment|witholding)\/[a-zA-Z0-9._-]+$/i',
            $storagePath
        )
    ) {
        log_message(
            'error',
            'Invalid contract attachment storage path: ' . $storagePath
        );

        return $this->failNotFound('Invalid attachment path.');
    }

    // 5. Resolve physical file path.
    $filePath = ROOTPATH
        . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);

    if (!is_file($filePath) || !is_readable($filePath)) {
        log_message(
            'error',
            'Contract attachment file missing: ' . $filePath
        );

        return $this->failNotFound(
            'Attachment file not found on the server.'
        );
    }

    // 6. Validate MIME type.
    $mimeType = (string) ($attachment['mime_type'] ?? '');

    $allowedTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
            'Unable to read contract attachment: ' . $filePath
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
            (string) ($attachment['file_name'] ?? 'attachment')
        )
    );

    // 9. Log original file information.
    log_message(
        'error',
        'CONTRACT ATTACHMENT: Original size = ' . strlen($content)
    );

    log_message(
        'error',
        'CONTRACT ATTACHMENT: Original SHA256 = ' . hash('sha256', $content)
    );

    log_message(
        'error',
        'CONTRACT ATTACHMENT: Original first bytes = '
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



    /**
     * Store a contract-related file and its metadata.
     *
     * Files are stored under ROOTPATH using the same directory structure
     * already used by the contract attachment workflow.
     *
     * contract_attachments columns:
     * contract_id, attachment_type, file_name, stored_file_name,
     * file_path, mime_type, file_size, uploaded_by, created_at, updated_at.
     */
    private function storeContractEvidence(
        string $contractId,
        string $type,
        $file,
        string $userId
    ): ?array {
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (!$file->isValid() || $file->hasMoved()) {
            throw new \RuntimeException('One of the submitted evidence files is invalid.');
        }

        if ($file->getSizeByUnit('mb') > 15) {
            throw new \RuntimeException('Maximum attachment size is 15 MB.');
        }

        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, $allowed, true)) {
            throw new \RuntimeException('One of the evidence files has an unsupported file type.');
        }

        $directories = [
            'CONTRACT' => 'file/contract/attechment',
            'PAYMENT' => 'file/contract/payment',
            'WITHHOLDING' => 'file/contract/witholding',
        ];

        if (!isset($directories[$type])) {
            throw new \RuntimeException('Invalid evidence type.');
        }

        $relativeDirectory = $directories[$type];

        $absoluteDirectory = rtrim(ROOTPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (
            !is_dir($absoluteDirectory) &&
            !mkdir($absoluteDirectory, 0755, true) &&
            !is_dir($absoluteDirectory)
        ) {
            throw new \RuntimeException('Unable to create the evidence storage directory.');
        }

        $storedName = $file->getRandomName();

        $file->move($absoluteDirectory, $storedName);

        $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $storedName;
        $relativePath = $relativeDirectory . '/' . $storedName;

        try {
            $now = date('Y-m-d H:i:s');

            $this->db->table('contract_attachments')->insert([
                'contract_id' => $contractId,
                'attachment_type' => $type,
                'file_name' => $file->getClientName(),
                'stored_file_name' => $storedName,
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $file->getSize(),
                'uploaded_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->db->table('contract_attachments')
                ->where('contract_id', $contractId)
                ->where('stored_file_name', $storedName)
                ->get()
                ->getRowArray();

            if (!$row) {
                throw new \RuntimeException('Could not retrieve the saved evidence attachment.');
            }

            // Used internally only for cleanup after a failed transaction.
            $row['absolute_path'] = $absolutePath;

            return $row;
        } catch (Throwable $e) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $e;
        }
    }

    public function uploadAttachment($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->validUuid((string) $contractId)) {
            return $this->respondError('Invalid contract ID.', 422);
        }

        $contract = $this->getContract((string) $contractId);

        if (!$contract) {
            return $this->respondError('Contract not found.', 404);
        }
        if ($this->isContractCompleted($contract)) {
            return $this->respondError('Attachments cannot be changed after the contract is completed.', 409);
        }

        $type = strtoupper((string) ($this->request->getPost('attachmentType') ?? 'CONTRACT'));

        if (!in_array($type, ['CONTRACT', 'PAYMENT', 'WITHHOLDING'], true)) {
            return $this->respondError('Invalid attachment type.', 422);
        }

        $evidence = null;

        $this->db->transBegin();

        try {
            $evidence = $this->storeContractEvidence(
                (string) $contractId,
                $type,
                $this->request->getFile('file'),
                (string) $auth['id']
            );

            if (!$evidence) {
                $this->db->transRollback();
                return $this->respondError('Please select a file to upload.', 422);
            }

            $now = date('Y-m-d H:i:s');

            // Only the financial evidence ID is updated for these two types.
            // Contract attachment metadata is stored in contract_attachments.
            if ($type === 'PAYMENT') {
                $this->db->table('contract_financial_information')
                    ->where('contract_id', $contractId)
                    ->update([
                        'payment_evidence_id' => $evidence['id'],
                        'payment_updated_at' => $now,
                        'updated_at' => $now,
                    ]);
            } elseif ($type === 'WITHHOLDING') {
                $this->db->table('contract_financial_information')
                    ->where('contract_id', $contractId)
                    ->update([
                        'withholding_evidence_id' => $evidence['id'],
                        'withholding_updated_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed while uploading the attachment.');
            }

            $this->db->transCommit();

            unset($evidence['absolute_path']);

            return $this->respondSuccess($this->mapAttachment($evidence), 201);
        } catch (Throwable $e) {
            $this->db->transRollback();

            if (
                !empty($evidence['absolute_path']) &&
                is_file($evidence['absolute_path'])
            ) {
                @unlink($evidence['absolute_path']);
            }

            log_message('error', 'Contract attachment upload failed: ' . $e->getMessage());

            return $this->respondError($e->getMessage(), 422);
        }
    }

    public function deleteAttachment($contractId = null, $attachmentId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        if (
            !$this->validUuid((string) $contractId) ||
            !$this->validUuid((string) $attachmentId)
        ) {
            return $this->respondError('Invalid contract or attachment ID.', 422);
        }

        $contract = $this->getContract((string) $contractId);
        if (!$contract) {
            return $this->respondError('Contract not found.', 404);
        }
        if ($this->isContractCompleted($contract)) {
            return $this->respondError('Attachments cannot be changed after the contract is completed.', 409);
        }

        $attachment = $this->db->table('contract_attachments')
            ->where('id', $attachmentId)
            ->where('contract_id', $contractId)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->respondError('Attachment not found.', 404);
        }

        $this->db->transBegin();

        try {
            $type = strtoupper((string) ($attachment['attachment_type'] ?? ''));

            if ($type === 'PAYMENT') {
                $this->db->table('contract_financial_information')
                    ->where('contract_id', $contractId)
                    ->where('payment_evidence_id', $attachmentId)
                    ->update([
                        'payment_evidence_id' => null,
                        'payment_updated_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } elseif ($type === 'WITHHOLDING') {
                $this->db->table('contract_financial_information')
                    ->where('contract_id', $contractId)
                    ->where('withholding_evidence_id', $attachmentId)
                    ->update([
                        'withholding_evidence_id' => null,
                        'withholding_updated_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            $this->db->table('contract_attachments')
                ->where('id', $attachmentId)
                ->where('contract_id', $contractId)
                ->delete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed while deleting the attachment.');
            }

            $this->db->transCommit();

            // Physical file cleanup remains separate from database deletion.
            return $this->respondSuccess(['deleted' => true]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Contract attachment deletion failed: ' . $e->getMessage());

            return $this->respondError('Unable to delete attachment.', 500);
        }
    }

    /* =========================================================
       FINANCIAL INFORMATION
    ========================================================= */

    private function getFinancialInformation(string $contractId): array
    {
        $row = $this->db->table('contract_financial_information')
            ->where('contract_id', $contractId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [
                'payment' => null,
                'withholding' => [
                    'enabled' => false,
                    'rate' => self::ROYALTY_RATE,
                    'evidence' => null,
                ],
                'paymentHistory' => [],
            ];
        }

        $paymentEvidence = null;

        if (!empty($row['payment_evidence_id'])) {
            $paymentEvidenceRow = $this->db->table('contract_attachments')
                ->where('id', $row['payment_evidence_id'])
                ->where('contract_id', $contractId)
                ->where('attachment_type', 'PAYMENT')
                ->get()
                ->getRowArray();

            if ($paymentEvidenceRow) {
                $paymentEvidence = $this->mapAttachment($paymentEvidenceRow);
            }
        }

        $withholdingEvidence = null;

        if (!empty($row['withholding_evidence_id'])) {
            $withholdingEvidenceRow = $this->db->table('contract_attachments')
                ->where('id', $row['withholding_evidence_id'])
                ->where('contract_id', $contractId)
                ->where('attachment_type', 'WITHHOLDING')
                ->get()
                ->getRowArray();

            if ($withholdingEvidenceRow) {
                $withholdingEvidence = $this->mapAttachment($withholdingEvidenceRow);
            }
        }

        $payment = null;

        if ($row['payment_bank'] !== null || $row['payment_sender'] !== null) {
            $payment = [
                'bank' => $row['payment_bank'],
                'paymentSender' => $row['payment_sender'],
                'paymentAmount' => (float) $row['payment_amount'],
                'paymentDate' => $this->dateToEpoch($row['payment_date']),
                'paymentReferenceNumber' => $row['payment_reference_number'],
                'paymentEvidence' => $paymentEvidence,
                'remark' => $row['payment_remark'],
                'recordedBy' => $row['payment_recorded_by'],
                'recordedAt' => !empty($row['payment_recorded_at'])
                    ? strtotime($row['payment_recorded_at']) * 1000 : null,
                'updatedAt' => !empty($row['payment_updated_at'])
                    ? strtotime($row['payment_updated_at']) * 1000 : null,
            ];
        }

        $historyRows = $this->db->table('contract_financial_history')
            ->where('contract_id', $contractId)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        $history = array_map(static function ($item) {
            return [
                'action' => $item['action'],
                'at' => strtotime($item['created_at']) * 1000,
                'by' => $item['changed_by'],
                'note' => $item['note'],
            ];
        }, $historyRows);

        return [
            'payment' => $payment,
            'withholding' => [
                'enabled' => (bool) $row['withholding_enabled'],
                'rate' => (float) $row['withholding_rate'],
                'evidence' => $withholdingEvidence,
                'recordedBy' => $row['withholding_recorded_by'],
                'recordedAt' => !empty($row['withholding_recorded_at'])
                    ? strtotime($row['withholding_recorded_at']) * 1000 : null,
                'updatedAt' => !empty($row['withholding_updated_at'])
                    ? strtotime($row['withholding_updated_at']) * 1000 : null,
            ],
            'paymentHistory' => $history,
        ];
    }

    public function financialInformation($contractId = null)
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        if (!$this->getContract((string) $contractId)) {
            return $this->respondError('Contract not found.', 404);
        }

        return $this->respondSuccess($this->getFinancialInformation((string) $contractId));
    }

    public function saveFinancialInformation($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($this->isContractCompleted($contract)) {
            return $this->respondError('Financial information cannot be changed after the contract is completed.', 409);
        }

        $data = $this->requestData();
        $payment = $data['payment'] ?? null;
        $withholding = $data['withholding'] ?? [];

        $enabled = (bool) ($withholding['enabled'] ?? false);
        $rate = (float) ($withholding['rate'] ?? self::ROYALTY_RATE);

        if ($rate < 0 || $rate > 1) {
            return $this->respondError('Withholding rate must be between 0 and 1.', 422);
        }

        $paymentEvidenceId = null;
        $withholdingEvidenceId = null;

        if (is_array($payment)) {
            $evidence = $payment['paymentEvidence'] ?? null;
            $paymentEvidenceId = is_array($evidence) ? ($evidence['id'] ?? null) : null;
        }

        $withholdingEvidence = $withholding['evidence'] ?? null;
        $withholdingEvidenceId = is_array($withholdingEvidence)
            ? ($withholdingEvidence['id'] ?? null)
            : null;

        if ($enabled && !$withholdingEvidenceId) {
            return $this->respondError('Withholding evidence is required when withholding is enabled.', 422);
        }

        foreach ([$paymentEvidenceId, $withholdingEvidenceId] as $evidenceId) {
            if ($evidenceId && !$this->validUuid((string) $evidenceId)) {
                return $this->respondError('Invalid evidence attachment reference.', 422);
            }
        }

        // Evidence IDs must belong to this contract and have the correct type.
        if ($paymentEvidenceId) {
            $validPaymentEvidence = $this->db->table('contract_attachments')
                ->where('id', $paymentEvidenceId)
                ->where('contract_id', $contractId)
                ->where('attachment_type', 'PAYMENT')
                ->countAllResults();

            if ($validPaymentEvidence !== 1) {
                return $this->respondError('The selected payment evidence is invalid or does not belong to this contract.', 422);
            }
        }

        if ($withholdingEvidenceId) {
            $validWithholdingEvidence = $this->db->table('contract_attachments')
                ->where('id', $withholdingEvidenceId)
                ->where('contract_id', $contractId)
                ->where('attachment_type', 'WITHHOLDING')
                ->countAllResults();

            if ($validWithholdingEvidence !== 1) {
                return $this->respondError('The selected withholding evidence is invalid or does not belong to this contract.', 422);
            }
        }

        $before = $this->getFinancialInformation((string) $contractId);

        $existing = $this->db->table('contract_financial_information')
            ->where('contract_id', $contractId)
            ->get()
            ->getRowArray();

        $now = date('Y-m-d H:i:s');

        $paymentDate = is_array($payment)
            ? $this->dateValue($payment['paymentDate'] ?? null)
            : null;

        if (is_array($payment)) {
            $amount = (float) ($payment['paymentAmount'] ?? 0);

            if ($amount < 0) {
                return $this->respondError('Payment amount cannot be negative.', 422);
            }
        }

        $paymentWasProvided = array_key_exists('payment', $data);

        $values = [
            'payment_bank' => is_array($payment) ? ($payment['bank'] ?? null) : null,
            'payment_sender' => is_array($payment) ? ($payment['paymentSender'] ?? null) : null,
            'payment_amount' => is_array($payment) ? (float) ($payment['paymentAmount'] ?? 0) : null,
            'payment_date' => is_array($payment) ? $paymentDate : null,
            'payment_reference_number' => is_array($payment) ? ($payment['paymentReferenceNumber'] ?? null) : null,
            'payment_remark' => is_array($payment) ? ($payment['remark'] ?? null) : null,
            'payment_evidence_id' => $paymentEvidenceId,
            'payment_recorded_by' => is_array($payment)
                ? ($existing['payment_recorded_by'] ?? $auth['id']) : null,
            'payment_recorded_at' => is_array($payment)
                ? ($existing['payment_recorded_at'] ?? $now) : null,
            'payment_updated_at' => is_array($payment) ? $now : null,
            'withholding_enabled' => $enabled,
            'withholding_rate' => $rate,
            'withholding_evidence_id' => $withholdingEvidenceId,
            'withholding_recorded_by' => $existing['withholding_recorded_by'] ?? $auth['id'],
            'withholding_recorded_at' => $existing['withholding_recorded_at'] ?? $now,
            'withholding_updated_at' => $now,
            'updated_at' => $now,
        ];

        $this->db->transBegin();

        try {
            if ($existing) {
                $this->db->table('contract_financial_information')
                    ->where('contract_id', $contractId)
                    ->update($values);
            } else {
                $values['contract_id'] = $contractId;
                $values['created_at'] = $now;

                $this->db->table('contract_financial_information')->insert($values);
            }

            $after = $this->getFinancialInformation((string) $contractId);

            if ($paymentWasProvided) {
                $action = $payment === null
                    ? 'PAYMENT_INFORMATION_CLEARED'
                    : ($before['payment'] ? 'PAYMENT_INFORMATION_UPDATED' : 'PAYMENT_INFORMATION_RECORDED');

                $this->recordFinancialHistory(
                    (string) $contractId,
                    $action,
                    $before['payment'],
                    $after['payment']
                );
            }

            $this->recordFinancialHistory(
                (string) $contractId,
                'WITHHOLDING_UPDATED',
                $before['withholding'],
                $after['withholding']
            );

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed while saving financial information.');
            }

            $this->db->transCommit();

            return $this->respondSuccess($after);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message('error', 'Contract financial save failed: ' . $e->getMessage());

            return $this->respondError('Unable to save contract financial information.', 500);
        }
    }

    /* =========================================================
       FINANCIAL REPORT
    ========================================================= */

    public function financialReport($contractId = null)
    {
        $auth = $this->requireActor();
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        $progress = $this->calculateProgress((string) $contractId);
        $financial = $this->getFinancialInformation((string) $contractId);

        $subtotal = (float) $progress['fulfilledSubtotal'];
        $vat = (float) $progress['vatAmount'];
        $gross = (float) $progress['fulfilledValue'];

        $withholdingEnabled = (bool) ($financial['withholding']['enabled'] ?? false);
        $withholdingRate = (float) ($financial['withholding']['rate'] ?? self::ROYALTY_RATE);

        $withholdingAmount = $withholdingEnabled
            ? round($gross * $withholdingRate, 2)
            : 0;

        return $this->respondSuccess([
            'contractId' => (string) $contractId,
            'contractNumber' => $contract['contract_number'],
            'customerName' => $contract['customer_name_snapshot'],
            'contractValue' => (float) $contract['contract_value'],
            'fulfilledValue' => $gross,
            'remainingValue' => $progress['remainingValue'],
            'fulfillmentPercentage' => $progress['fulfillmentPercentage'],
            'basis' => 'COMPLETED_ORDERS',
            'grossDue' => $gross,
            'subtotal' => $subtotal,
            'vatAmount' => $vat,
            'withholdingAmount' => $withholdingAmount,
            'netAmount' => round($gross - $withholdingAmount, 2),
            'amountPaid' => 0,
            'outstandingAmount' => round($gross - $withholdingAmount, 2),
            'payment' => $financial['payment'],
            'withholding' => $financial['withholding'],
            'paymentHistory' => $financial['paymentHistory'],
            'invoice' => null,
            'invoiceError' => null,
        ]);
    }

    /* =========================================================
       SALES INVOICE CONVERSION HANDOFF
    ========================================================= */

    /* =========================================================
       LEGACY CONVERSION ENDPOINTS
    ========================================================= */

    /**
     * Sales-invoice conversion is no longer a contract lifecycle state.
     * Contract orders already create their linked sales invoices individually.
     */
    public function startConversion($contractId = null)
    {
        return $this->respondError(
            'Contract conversion is no longer part of the contract lifecycle.',
            410
        );
    }

    public function markConverted($contractId = null)
    {
        return $this->respondError(
            'Contract conversion is no longer part of the contract lifecycle.',
            410
        );
    }

    public function conversionFailed($contractId = null)
    {
        return $this->respondError(
            'Contract conversion is no longer part of the contract lifecycle.',
            410
        );
    }
}
