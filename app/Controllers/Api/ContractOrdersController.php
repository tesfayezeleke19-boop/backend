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
        'IN_PROGRESS',
        'READY_FOR_COMPLETION',
        'CONVERTED',
        'CANCELLED',
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
        $json = $this->request->getJSON(true);

        if (is_array($json)) {
            return $json;
        }

        return $this->request->getPost() ?? [];
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

        // Frontend date values may be epoch milliseconds.
        if (is_numeric($value)) {
            $timestamp = (int) floor(((float) $value) / 1000);
            return gmdate('Y-m-d', $timestamp);
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

        // Use noon UTC to avoid local-time date rollover in browsers.
        $timestamp = strtotime((string) $value . ' 12:00:00 UTC');

        return $timestamp === false ? null : $timestamp * 1000;
    }

    private function number($value): float
    {
        return round((float) $value, 2);
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

    private function isContractClosed(array $contract): bool
    {
        return in_array(
            strtoupper((string) $contract['status']),
            ['CONVERTED', 'CANCELLED'],
            true
        );
    }

    private function canAcceptOrders(array $contract): bool
    {
        return in_array(
            strtoupper((string) $contract['status']),
            ['IN_PROGRESS', 'READY_FOR_COMPLETION'],
            true
        ) && !$contract['sales_invoice_id'];
    }

    private function isConverted(array $contract): bool
    {
        return !empty($contract['sales_invoice_id']) ||
            strtoupper((string) $contract['status']) === 'CONVERTED';
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

    private function recordOrderEdit(
        string $orderId,
        array $before,
        array $after,
        ?string $reason = null
    ): void {
        $actor = $this->actor();

        $this->db->table('contract_order_edit_history')->insert([
            'contract_order_id' => $orderId,
            'changed_by' => $actor['id'] ?? null,
            'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
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
        // Serialize number generation inside the current transaction.
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
            'fileUrl' => (string) $row['file_url'],
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

        $subtotal = (float) $row['quantity'] * (float) $row['unit_price'];
        $vat = $subtotal * self::VAT_RATE;

        $edit = $this->db->table('contract_order_edit_history h')
            ->select('h.changed_by, h.created_at, u.email')
            ->join('users u', 'u.id = h.changed_by', 'left')
            ->where('h.contract_order_id', $row['id'])
            ->orderBy('h.created_at', 'DESC')
            ->get()
            ->getRowArray();

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
        if ($auth instanceof ResponseInterface) return $auth;

        $builder = $this->db->table('contracts c')
            ->select('c.*')
            ->orderBy('c.created_at', 'DESC');

        $status = $this->request->getGet('status');
        $search = trim((string) $this->request->getGet('search'));

        if ($status && in_array($status, self::CONTRACT_STATUSES, true)) {
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
    $customer = $data['customer'] ?? [];

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

    $this->db->transBegin();

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

        $this->recordContractStatus($created['id'], 'DRAFT', 'Contract created');

        $this->db->transCommit();

        return $this->respondSuccess($this->mapContract($created), 201);
    } catch (Throwable $e) {
        $this->db->transRollback();
        log_message('error', 'Contract create failed: ' . $e->getMessage());

        return $this->respondError('Unable to create contract.', 500);
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
                'status' => 'IN_PROGRESS',
                'activated_at' => $now,
                'activated_by' => $auth['id'],
                'updated_at' => $now,
                'version' => ((int) $contract['version']) + 1,
            ]);

            $this->recordContractStatus((string) $id, 'IN_PROGRESS', 'Contract activated');

            $this->db->transCommit();

            return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract activation failed: ' . $e->getMessage());
            return $this->respondError('Unable to activate contract.', 500);
        }
    }

    public function requestCompletion($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $id);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if (!in_array($contract['status'], ['IN_PROGRESS', 'READY_FOR_COMPLETION'], true)) {
            return $this->respondError('This contract cannot be submitted for completion review.', 409);
        }

        $progress = $this->calculateProgress((string) $id);
        if ($progress['fulfilledValue'] + 0.01 < (float) $contract['contract_value']) {
            return $this->respondError('Completed orders have not reached the contract value.', 409);
        }

        $this->db->table('contracts')->where('id', $id)->update([
            'status' => 'READY_FOR_COMPLETION',
            'ready_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->recordContractStatus((string) $id, 'READY_FOR_COMPLETION', 'Submitted for completion review');

        return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
    }

    public function confirmCompletion($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $id);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if (!in_array($contract['status'], ['IN_PROGRESS', 'READY_FOR_COMPLETION'], true)) {
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
            'status' => 'READY_FOR_COMPLETION',
            'completion_confirmed_at' => $now,
            'completion_confirmed_by' => $auth['id'],
            'completion_reason' => $reason !== '' ? $reason : null,
            'closed_below_value' => $below,
            'updated_at' => $now,
        ]);

        $this->recordContractStatus(
            (string) $id,
            'READY_FOR_COMPLETION',
            $below ? 'Completion confirmed below contract value: ' . $reason : 'Completion confirmed'
        );

        return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
    }

    public function cancel($id = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $id);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($this->isConverted($contract)) {
            return $this->respondError('A converted contract cannot be cancelled.', 409);
        }

        if ($contract['status'] === 'CANCELLED') {
            return $this->respondError('This contract is already cancelled.', 409);
        }

        $data = $this->requestData();
        $reason = trim((string) ($data['reason'] ?? $data['cancellationReason'] ?? ''));

        if ($reason === '') {
            return $this->respondError('Cancellation reason is required.', 422);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $this->db->table('contracts')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'cancelled_at' => $now,
                'cancelled_by' => $auth['id'],
                'cancellation_reason' => $reason,
                'updated_at' => $now,
            ]);

            $this->recordContractStatus((string) $id, 'CANCELLED', $reason);

            $this->db->transCommit();

            return $this->respondSuccess($this->mapContract($this->getContract((string) $id)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract cancellation failed: ' . $e->getMessage());
            return $this->respondError('Unable to cancel contract.', 500);
        }
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

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if (!$this->canAcceptOrders($contract)) {
            return $this->respondError('This contract is not accepting orders.', 409);
        }

        $data = $this->requestData();

        $aggregate = trim((string) ($data['aggregate'] ?? ''));
        $quantity = (float) ($data['quantity'] ?? 0);
        $royaltyInvoiceNumber = trim((string) ($data['royaltyInvoiceNumber'] ?? ''));
        $orderDate = $this->dateValue($data['orderDate'] ?? null);

        if ($aggregate === '' || $quantity <= 0 || !$orderDate) {
            return $this->respondError('Aggregate, valid quantity and order date are required.', 422);
        }

        if ($royaltyInvoiceNumber === '') {
            return $this->respondError('Royalty invoice number is required.', 422);
        }

        $aggregateRow = $this->db->table('aggregate_types')
            ->where('name', $aggregate)
            ->where('is_active', true)
            ->get()
            ->getRowArray();

        if (!$aggregateRow) {
            return $this->respondError('The selected aggregate type is unavailable.', 422);
        }

        $unitPrice = (float) $aggregateRow['price_per_m3'];

        $nextOrder = $this->db->table('contract_orders')
            ->selectMax('order_number', 'last_number')
            ->where('contract_id', $contractId)
            ->get()
            ->getRowArray();

        $orderNumber = ((int) ($nextOrder['last_number'] ?? 0)) + 1;
        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $this->db->table('contract_orders')->insert([
                'contract_id' => $contractId,
                'order_number' => $orderNumber,
                'aggregate' => $aggregate,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'royalty_invoice_number' => $royaltyInvoiceNumber,
                'order_date' => $orderDate,
                'status' => 'COMPLETED',
                'completed_by' => $auth['id'],
                'completed_at' => $now,
                'created_by' => $auth['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = $this->db->table('contract_orders')
                ->where('contract_id', $contractId)
                ->where('order_number', $orderNumber)
                ->get()
                ->getRowArray();

            if (!$order) {
                throw new \RuntimeException('Could not retrieve the created order.');
            }

            $this->applyReadiness((string) $contractId);

            $this->db->transCommit();

            return $this->respondSuccess($this->mapOrder($order), 201);
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract order create failed: ' . $e->getMessage());
            return $this->respondError('Unable to create contract order.', 500);
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

        if ($this->isContractClosed($contract) || $this->isConverted($contract)) {
            return $this->respondError('Orders cannot be edited after contract closure or conversion.', 409);
        }

        if ($order['status'] === 'CANCELLED') {
            return $this->respondError('Cancelled orders cannot be edited.', 409);
        }

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

        // Preserve the historical price when the aggregate remains unchanged.
        $unitPrice = $aggregate === $order['aggregate']
            ? (float) $order['unit_price']
            : (float) $aggregateRow['price_per_m3'];

        $before = [
            'aggregate' => $order['aggregate'],
            'quantity' => (float) $order['quantity'],
            'unit_price' => (float) $order['unit_price'],
            'royalty_invoice_number' => $order['royalty_invoice_number'],
            'order_date' => $order['order_date'],
        ];

        $after = [
            'aggregate' => $aggregate,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'royalty_invoice_number' => $royaltyInvoiceNumber,
            'order_date' => $orderDate,
        ];

        $this->db->transBegin();

        try {
            $this->recordOrderEdit(
                (string) $orderId,
                $before,
                $after,
                $data['reason'] ?? null
            );

            $this->db->table('contract_orders')->where('id', $orderId)->update([
                'aggregate' => $aggregate,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'royalty_invoice_number' => $royaltyInvoiceNumber,
                'order_date' => $orderDate,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->applyReadiness((string) $order['contract_id']);

            $this->db->transCommit();

            return $this->respondSuccess($this->mapOrder($this->getOrder((string) $orderId)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract order update failed: ' . $e->getMessage());
            return $this->respondError('Unable to update contract order.', 500);
        }
    }

    public function cancelOrder($orderId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $order = $this->getOrder((string) $orderId);
        if (!$order) return $this->respondError('Order not found.', 404);

        $contract = $this->getContract((string) $order['contract_id']);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($this->isConverted($contract)) {
            return $this->respondError('Orders belonging to a converted contract cannot be cancelled.', 409);
        }

        if ($order['status'] === 'CANCELLED') {
            return $this->respondError('Order is already cancelled.', 409);
        }

        $data = $this->requestData();
        $reason = trim((string) ($data['reason'] ?? $data['cancellationReason'] ?? ''));

        if ($reason === '') {
            return $this->respondError('Cancellation reason is required.', 422);
        }

        $this->db->transBegin();

        try {
            $this->db->table('contract_orders')->where('id', $orderId)->update([
                'status' => 'CANCELLED',
                'cancelled_by' => $auth['id'],
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancellation_reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->applyReadiness((string) $order['contract_id']);

            $this->db->transCommit();

            return $this->respondSuccess($this->mapOrder($this->getOrder((string) $orderId)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Contract order cancellation failed: ' . $e->getMessage());
            return $this->respondError('Unable to cancel order.', 500);
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
            return strtoupper((string) $order['status']) === 'COMPLETED';
        });

        $cancelled = array_filter($orders, static function ($order) {
            return strtoupper((string) $order['status']) === 'CANCELLED';
        });

        $subtotal = 0.0;

        foreach ($completed as $order) {
            $subtotal += (float) $order['quantity'] * (float) $order['unit_price'];
        }

        $vat = $subtotal * self::VAT_RATE;
        $fulfilledValue = $subtotal + $vat;
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

    private function applyReadiness(string $contractId): void
    {
        $contract = $this->getContract($contractId);

        if (!$contract || $this->isContractClosed($contract) || $this->isConverted($contract)) {
            return;
        }

        // Completion confirmation is a Finance decision and is not undone
        // automatically by a later order edit.
        if (!empty($contract['completion_confirmed_at'])) {
            return;
        }

        $progress = $this->calculateProgress($contractId);

        $nextStatus = $progress['fulfilledValue'] + 0.01 >= (float) $contract['contract_value']
            ? 'READY_FOR_COMPLETION'
            : 'IN_PROGRESS';

        if ($contract['status'] !== $nextStatus) {
            $this->db->table('contracts')->where('id', $contractId)->update([
                'status' => $nextStatus,
                'ready_at' => $nextStatus === 'READY_FOR_COMPLETION'
                    ? date('Y-m-d H:i:s') : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->recordContractStatus($contractId, $nextStatus, 'Updated from completed order totals');
        }
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

    public function uploadAttachment($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        $type = strtoupper((string) ($this->request->getPost('attachmentType') ?? 'CONTRACT'));

        if (!in_array($type, ['CONTRACT', 'PAYMENT', 'WITHHOLDING'], true)) {
            return $this->respondError('Invalid attachment type.', 422);
        }

        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->respondError('Please select a valid file to upload.', 422);
        }

        if ($file->getSizeByUnit('mb') > 15) {
            return $this->respondError('Maximum attachment size is 15 MB.', 422);
        }

        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        if (!in_array($file->getMimeType(), $allowed, true)) {
            return $this->respondError('This file type is not allowed.', 422);
        }

        try {
            $directory = WRITEPATH . 'uploads/contracts/' . $contractId;

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create upload directory.');
            }

            $storedName = $file->getRandomName();
            $file->move($directory, $storedName);

            $relativePath = 'uploads/contracts/' . $contractId . '/' . $storedName;
            $fileUrl = base_url($relativePath);

            $this->db->table('contract_attachments')->insert([
                'contract_id' => $contractId,
                'attachment_type' => $type,
                'file_name' => $file->getClientName(),
                'file_url' => $fileUrl,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $auth['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $row = $this->db->table('contract_attachments')
                ->where('contract_id', $contractId)
                ->where('file_url', $fileUrl)
                ->get()
                ->getRowArray();

            return $this->respondSuccess($row ? $this->mapAttachment($row) : null, 201);
        } catch (Throwable $e) {
            log_message('error', 'Contract attachment upload failed: ' . $e->getMessage());
            return $this->respondError('Unable to upload attachment.', 500);
        }
    }

    public function deleteAttachment($contractId = null, $attachmentId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $attachment = $this->db->table('contract_attachments')
            ->where('id', $attachmentId)
            ->where('contract_id', $contractId)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->respondError('Attachment not found.', 404);
        }

        // Remove the database reference. Physical files can be cleaned separately.
        $this->db->table('contract_attachments')
            ->where('id', $attachmentId)
            ->delete();

        return $this->respondSuccess(['deleted' => true]);
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

        if ($this->isConverted($contract)) {
            return $this->respondError('Financial information cannot be changed after invoice conversion.', 409);
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

    public function startConversion($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        if ($this->isConverted($contract)) {
            return $this->respondSuccess($this->mapContract($contract));
        }

        if ($contract['status'] === 'CANCELLED') {
            return $this->respondError('Cancelled contracts cannot be converted.', 409);
        }

        $progress = $this->calculateProgress((string) $contractId);

        if ($progress['completedOrders'] < 1) {
            return $this->respondError('There are no completed orders to convert.', 409);
        }

        $this->db->table('contracts')->where('id', $contractId)->update([
            'conversion_started_at' => date('Y-m-d H:i:s'),
            'conversion_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondSuccess([
            'contract' => $this->mapContract($this->getContract((string) $contractId)),
            'orders' => $this->db->table('contract_orders')
                ->where('contract_id', $contractId)
                ->where('status', 'COMPLETED')
                ->orderBy('order_number', 'ASC')
                ->get()
                ->getResultArray(),
        ]);
    }

    public function markConverted($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        $data = $this->requestData();
        $invoiceId = $data['salesInvoiceId'] ?? $data['invoiceId'] ?? null;

        if (!$this->validUuid((string) $invoiceId)) {
            return $this->respondError('A valid Sales Invoice ID is required.', 422);
        }

        if ($this->isConverted($contract)) {
            if ((string) $contract['sales_invoice_id'] === (string) $invoiceId) {
                return $this->respondSuccess($this->mapContract($contract));
            }

            return $this->respondError('This contract has already been converted to another invoice.', 409);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $this->db->table('contracts')->where('id', $contractId)->update([
                'sales_invoice_id' => $invoiceId,
                'status' => 'CONVERTED',
                'converted_at' => $now,
                'converted_by' => $auth['id'],
                'conversion_error' => null,
                'updated_at' => $now,
            ]);

            $this->recordContractStatus((string) $contractId, 'CONVERTED', 'Converted to Sales Invoice ' . $invoiceId);

            $this->db->transCommit();

            return $this->respondSuccess($this->mapContract($this->getContract((string) $contractId)));
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Mark contract converted failed: ' . $e->getMessage());
            return $this->respondError('Unable to mark contract as converted.', 500);
        }
    }

    public function conversionFailed($contractId = null)
    {
        $auth = $this->requireRoles(['FINANCE', 'ADMIN']);
        if ($auth instanceof ResponseInterface) return $auth;

        $contract = $this->getContract((string) $contractId);
        if (!$contract) return $this->respondError('Contract not found.', 404);

        $data = $this->requestData();
        $message = trim((string) ($data['message'] ?? 'Sales Invoice conversion failed.'));

        if ($this->isConverted($contract)) {
            return $this->respondError('The contract is already converted.', 409);
        }

        $this->db->table('contracts')->where('id', $contractId)->update([
            'conversion_error' => $message,
            'conversion_started_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondSuccess($this->mapContract($this->getContract((string) $contractId)));
    }
}
