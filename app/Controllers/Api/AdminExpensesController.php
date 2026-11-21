<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

class AdminExpensesController extends BaseController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
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

    private function body(): array
    {
        $json = $this->request->getJSON(true);
        return is_array($json) ? $json : [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(16384, 20479),
            random_int(32768, 49151),
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535)
        );
    }

    private function newExpenseNumber(): string
    {
        return 'EXP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

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

        if (strtoupper(trim((string) ($user['role'] ?? ''))) !== 'ADMIN') {
            $this->authenticatedUser = $user;
            return true;
        }

        try {
            $this->db->table('user_sessions')
                ->where('id', $session['id'])
                ->update(['last_used_at' => $this->now()]);
        } catch (Throwable $ignored) {
            // Optional audit update.
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
            return $this->fail('Only Admin users can perform this operation.', 403);
        }

        return null;
    }

    private function userId(): string
    {
        return (string) ($this->authenticatedUser['id'] ?? '');
    }

    private function findExpense(string $id): ?array
    {
        return $this->db->table('expenses')
            ->where('id', $id)
            ->where('expense_source', 'ADMIN')
            ->get()
            ->getRowArray() ?: null;
    }

    private function addHistory(string $expenseId, string $status, ?string $reason = null): void
    {
        $this->db->table('expense_status_history')->insert([
            'id' => $this->newUuid(),
            'expense_id' => $expenseId,
            'status' => $status,
            'changed_by' => $this->userId(),
            'reason' => $reason,
            'created_at' => $this->now(),
        ]);
    }

    private function strictDate($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(
                'Expense date is required in YYYY-MM-DD format.'
            );
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date ||
            $date->format('Y-m-d') !== $value ||
            (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))
        ) {
            throw new \InvalidArgumentException(
                'Expense date must be a valid date in YYYY-MM-DD format.'
            );
        }

        return $value;
    }

    private function attachmentId(array $input, array $keys): ?string
    {
        $value = null;

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key];
                break;
            }
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException(
                'Attachment references must be attachment IDs.'
            );
        }

        $id = trim((string) $value);

        if ($id === '') {
            return null;
        }

        if (!$this->db->table('attachments')->where('id', $id)->countAllResults()) {
            throw new \InvalidArgumentException(
                'The selected attachment does not exist. Upload the file first and use its attachment ID.'
            );
        }

        return $id;
    }

    /**
     * Map frontend input to database columns.
     *
     * expense_category = STANDARD or CASUAL
     * expense_type     = actual expense type, e.g. Fuel, Rent, Maintenance
     *
     * Supports both explicit API fields and the existing frontend payload:
     * expenseType = STANDARD/CASUAL
     * category    = actual expense type
     */
    private function mapInput(array $input): array
    {
        $date = $this->strictDate(
            $input['date'] ?? $input['expenseDate'] ?? null
        );

        /*
         * Resolve classification.
         *
         * Explicit database/API fields take priority.
         * The existing frontend uses expenseType for STANDARD/CASUAL.
         */
        $expenseCategoryValue =
            $input['expense_category']
            ?? $input['expenseCategory']
            ?? null;

        if ($expenseCategoryValue === null) {
            $frontendExpenseType = strtoupper(trim((string) (
                $input['expenseType'] ?? ''
            )));

            if (in_array($frontendExpenseType, ['STANDARD', 'CASUAL'], true)) {
                $expenseCategoryValue = $frontendExpenseType;
            }
        }

        // Support category as a classification only when it contains a valid classification.
        if ($expenseCategoryValue === null) {
            $legacyCategory = strtoupper(trim((string) (
                $input['category'] ?? ''
            )));

            if (in_array($legacyCategory, ['STANDARD', 'CASUAL'], true)) {
                $expenseCategoryValue = $legacyCategory;
            }
        }

        $expenseCategory = strtoupper(trim((string) (
            $expenseCategoryValue ?? ''
        )));

        /*
         * Resolve actual expense type.
         *
         * Explicit expense_type takes priority.
         * Existing frontend category contains the actual expense type.
         */
        $expenseTypeValue =
            $input['expense_type']
            ?? $input['type']
            ?? null;

        if ($expenseTypeValue === null) {
            $frontendExpenseType = trim((string) (
                $input['expenseType'] ?? ''
            ));

            if (!in_array(strtoupper($frontendExpenseType), ['STANDARD', 'CASUAL'], true)) {
                $expenseTypeValue = $frontendExpenseType;
            }
        }

        if ($expenseTypeValue === null) {
            $legacyCategory = trim((string) (
                $input['category'] ?? ''
            ));

            if (!in_array(strtoupper($legacyCategory), ['STANDARD', 'CASUAL'], true)) {
                $expenseTypeValue = $legacyCategory;
            }
        }

        $expenseType = trim((string) ($expenseTypeValue ?? ''));

        $description = trim((string) (
            $input['item'] ?? $input['description'] ?? ''
        ));

        $baseValue = $input['amountBeforeVat']
            ?? $input['amount_excl_vat']
            ?? null;

        if (!in_array($expenseCategory, ['STANDARD', 'CASUAL'], true)) {
            throw new \InvalidArgumentException(
                'Expense category must be STANDARD or CASUAL.'
            );
        }

        if ($expenseType === '') {
            throw new \InvalidArgumentException(
                'Expense type is required.'
            );
        }

        if ($description === '' || !is_numeric($baseValue)) {
            throw new \InvalidArgumentException(
                'Description and amount before VAT are required.'
            );
        }

        $base = (float) $baseValue;

        if (!is_finite($base) || $base < 0) {
            throw new \InvalidArgumentException(
                'Expense amount must be a valid non-negative number.'
            );
        }

        /*
         * VAT detection.
         * Explicit VAT flag takes priority.
         * If missing, infer it from the supplied VAT amount or total amount.
         */
        if (array_key_exists('vatEnabled', $input)) {
            $vatEnabled = filter_var(
                $input['vatEnabled'],
                FILTER_VALIDATE_BOOLEAN
            );
        } elseif (array_key_exists('vat_enabled', $input)) {
            $vatEnabled = filter_var(
                $input['vat_enabled'],
                FILTER_VALIDATE_BOOLEAN
            );
        } else {
            $sentVatAmount = $input['vatAmount']
                ?? $input['vat_amount']
                ?? null;

            $sentTotalAmount = $input['amount'] ?? null;

            $vatEnabled = (
                (is_numeric($sentVatAmount) && (float) $sentVatAmount > 0) ||
                (
                    is_numeric($sentTotalAmount) &&
                    (float) $sentTotalAmount > $base
                )
            );
        }

        $whtEnabled = filter_var(
            $input['whtEnabled'] ?? $input['wht_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        // CASUAL expenses do not use VAT.
        if ($expenseCategory === 'CASUAL') {
            $vatEnabled = false;
        }

        $vatRate = $input['vatRate'] ?? $input['vat_rate'] ?? 0.15;
        $whtRate = $input['whtRate'] ?? $input['wht_rate'] ?? 0.03;

        if (!is_numeric($vatRate) || !is_numeric($whtRate)) {
            throw new \InvalidArgumentException('Tax rates must be numeric.');
        }

        $vatRate = (float) $vatRate;
        $whtRate = (float) $whtRate;

        if (
            !is_finite($vatRate) ||
            !is_finite($whtRate) ||
            $vatRate < 0 ||
            $vatRate > 1 ||
            $whtRate < 0 ||
            $whtRate > 1
        ) {
            throw new \InvalidArgumentException(
                'Tax rates must be between 0 and 1 (for example, 0.15 for 15%).'
            );
        }

        /*
         * Calculate VAT.
         * Use the frontend VAT amount when supplied.
         * Otherwise derive it from the total or calculate it using the VAT rate.
         */
        $vat = 0.00;

        if ($vatEnabled) {
            $sentVatAmount = $input['vatAmount']
                ?? $input['vat_amount']
                ?? null;

            if ($sentVatAmount !== null && $sentVatAmount !== '') {
                if (!is_numeric($sentVatAmount)) {
                    throw new \InvalidArgumentException(
                        'VAT amount must be numeric.'
                    );
                }

                $vat = round((float) $sentVatAmount, 2);
            } else {
                $sentTotalAmount = $input['amount'] ?? null;

                if (
                    is_numeric($sentTotalAmount) &&
                    (float) $sentTotalAmount >= $base
                ) {
                    $vat = round((float) $sentTotalAmount - $base, 2);
                } else {
                    $vat = round($base * $vatRate, 2);
                }
            }

            if (!is_finite($vat) || $vat < 0) {
                throw new \InvalidArgumentException(
                    'VAT amount must be a valid non-negative number.'
                );
            }

            if (
                !array_key_exists('vatRate', $input) &&
                !array_key_exists('vat_rate', $input) &&
                $base > 0
            ) {
                $vatRate = round($vat / $base, 6);
            }
        }

        /*
         * WHT is calculated on the amount including VAT.
         */
        $wht = $whtEnabled
            ? round(($base + $vat) * $whtRate, 2)
            : 0.00;

        /*
         * Total expense amount includes VAT.
         * WHT is deducted separately when calculating transferred amount.
         */
        $gross = round($base + $vat, 2);

        $invoiceAttachmentId = $this->attachmentId(
            $input,
            ['invoiceAttachmentId', 'attachment_id', 'invoiceAttachment']
        );

        $whtAttachmentId = $this->attachmentId(
            $input,
            ['whtAttachmentId', 'wht_attachment_id', 'whtAttachment']
        );

        return [
            'expense_date' => $date,

            'expense_category' => $expenseCategory,
            'expense_type' => $expenseType,

            'description' => $description,

            'supplier_name' => $this->nullableText(
                $input['supplierName'] ?? $input['supplier_name'] ?? null
            ),

            'supplier_tin' => $this->nullableText(
                $input['supplierTin'] ?? $input['supplier_tin'] ?? null
            ),

            'amount_excl_vat' => round($base, 2),

            'vat_enabled' => $vatEnabled,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,

            'wht_enabled' => $whtEnabled,
            'wht_rate' => $whtRate,
            'wht_amount' => $wht,

            'total_amount' => $gross,

            'bank' => $this->nullableText($input['bank'] ?? null),

            'payment_reference' => $this->nullableText(
                $input['paymentReference']
                ?? $input['payment_reference']
                ?? null
            ),

            'payment_party' => $this->nullableText(
                $input['paymentParty']
                ?? $input['payment_party']
                ?? null
            ),

            'remark' => $this->nullableText(
                $input['remark'] ?? $input['remarkText'] ?? null
            ),

            'attachment_id' => $invoiceAttachmentId,
            'wht_attachment_id' => $whtAttachmentId,

            'updated_at' => $this->now(),
        ];
    }

    private function nullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(
                'Text fields must be plain text.'
            );
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function formatExpense(array $row): array
    {
        $status = strtoupper((string) ($row['status'] ?? 'DRAFT'));

        $history = $this->db->table('expense_status_history')
            ->where('expense_id', $row['id'])
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        $expenseCategory = strtoupper(trim((string) (
            $row['expense_category'] ?? 'STANDARD'
        )));

        $expenseType = trim((string) (
            $row['expense_type'] ?? ''
        ));

        return [
            'id' => $row['id'],
            'expenseNumber' => $row['expense_number'] ?? null,
            'expenseSource' => 'ADMIN',

            'date' => $row['expense_date'],

            'expense_category' => $expenseCategory,
            'expenseCategory' => $expenseCategory,
            'category' => $expenseCategory,

            'expense_type' => $expenseType,
            'expenseType' => $expenseType,
            'type' => $expenseType,

            'item' => $row['description'],
            'supplierName' => $row['supplier_name'] ?? null,
            'supplierTin' => $row['supplier_tin'] ?? null,

            'amountBeforeVat' => (float) $row['amount_excl_vat'],

            'vatEnabled' => (bool) $row['vat_enabled'],
            'vatRate' => (float) $row['vat_rate'],
            'vatAmount' => (float) $row['vat_amount'],

            'whtEnabled' => (bool) $row['wht_enabled'],
            'whtRate' => (float) $row['wht_rate'],
            'whtAmount' => (float) $row['wht_amount'],

            'amount' => (float) $row['total_amount'],

            'totalTransferred' => round(
                (float) $row['total_amount'] - (float) $row['wht_amount'],
                2
            ),

            'bank' => $row['bank'] ?? null,
            'paymentReference' => $row['payment_reference'] ?? null,
            'paymentParty' => $row['payment_party'] ?? null,
            'remark' => $row['remark'] ?? null,

            'status' => $status,
            'reviewStatus' => $status === 'SUBMITTED'
                ? 'PENDING_FINANCE_REVIEW'
                : $status,

            'isCounted' => $status === 'APPROVED',

            'submittedBy' => $row['submitted_by'] ?? null,
            'submittedAt' => $row['submitted_at'] ?? null,
            'reviewedBy' => $row['reviewed_by'] ?? null,
            'reviewedAt' => $row['reviewed_at'] ?? null,
            'rejectionReason' => $row['rejection_reason'] ?? null,

            'invoiceAttachmentId' => $row['attachment_id'] ?? null,
            'whtAttachmentId' => $row['wht_attachment_id'] ?? null,

            'createdBy' => $row['created_by'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,

            'version' => (int) ($row['version'] ?? 1),

            'statusHistory' => array_map(
                static fn(array $item): array => [
                    'id' => $item['id'],
                    'status' => $item['status'],
                    'changedBy' => $item['changed_by'],
                    'reason' => $item['reason'],
                    'createdAt' => $item['created_at'],
                ],
                $history
            ),
        ];
    }

    /**
     * POST /api/admin/expenses/attachments/upload
     */
    public function uploadAttachment(): ResponseInterface
    {
        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->fail('Choose a valid file to upload.', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->fail('File size must not exceed 10 MB.', 422);
        }

        $mimeType = $file->getMimeType();

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mimeType])) {
            return $this->fail(
                'Only PDF, JPG, PNG, and WebP files are allowed.',
                422
            );
        }

        $directory = ROOTPATH . 'file' . DIRECTORY_SEPARATOR . 'expense';

        if (
            !is_dir($directory) &&
            !mkdir($directory, 0750, true) &&
            !is_dir($directory)
        ) {
            return $this->fail(
                'Expense upload directory could not be created.',
                500
            );
        }

        if (!is_writable($directory)) {
            return $this->fail(
                'Expense upload directory is not writable by the server.',
                500
            );
        }

        $id = $this->newUuid();
        $originalName = basename((string) $file->getClientName());
        $fileSize = (int) $file->getSize();
        $storedName = $id . '.' . $allowed[$mimeType];
        $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;

        try {
            $file->move($directory, $storedName, true);

            if (!is_file($storedPath)) {
                throw new \RuntimeException(
                    'Uploaded file was not found at the destination path.'
                );
            }

            $this->db->transBegin();

            $this->db->table('attachments')->insert([
                'id' => $id,
                'file_name' => $originalName,
                'storage_key' => 'file/expense/' . $storedName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'uploaded_by' => $this->userId(),
                'created_at' => $this->now(),
            ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                throw new \RuntimeException(
                    'Could not save attachment metadata.'
                );
            }

            $this->db->transCommit();

            return $this->respondJson([
                'id' => $id,
                'fileName' => $originalName,
                'mimeType' => $mimeType,
                'fileSize' => $fileSize,
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }

            if (is_file($storedPath)) {
                @unlink($storedPath);
            }

            log_message(
                'error',
                'Admin expense attachment upload failed: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->fail(
                'File upload failed. Check the server log.',
                500
            );
        }
    }

    /** GET /api/admin/expenses */
    public function index(): ResponseInterface
    {
        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        $builder = $this->db->table('expenses')
            ->where('expense_source', 'ADMIN');

        $status = strtoupper(trim((string) $this->request->getGet('status')));

        $allowedStatuses = [
            'DRAFT',
            'SUBMITTED',
            'APPROVED',
            'REJECTED',
        ];

        if ($status !== '') {
            if (!in_array($status, $allowedStatuses, true)) {
                return $this->fail(
                    'Invalid expense status filter.',
                    422
                );
            }

            $builder->where('status', $status);
        }

        $rows = $builder
            ->orderBy('expense_date', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respondJson(
            array_map(
                fn(array $row): array => $this->formatExpense($row),
                $rows
            )
        );
    }

    /** GET /api/admin/expenses/{id} */
    public function show($id = null): ResponseInterface
    {
        if (!$id) {
            return $this->fail('Expense ID is required.', 400);
        }

        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense((string) $id);

        return $row
            ? $this->respondJson($this->formatExpense($row))
            : $this->fail('Admin expense not found.', 404);
    }

    /** POST /api/admin/expenses */
    public function create(): ResponseInterface
    {
        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        try {
            $fields = $this->mapInput($this->body());

            $id = $this->newUuid();
            $now = $this->now();

            $this->db->transBegin();

            $this->db->table('expenses')->insert(array_merge($fields, [
                'id' => $id,
                'expense_number' => $this->newExpenseNumber(),
                'expense_source' => 'ADMIN',
                'status' => 'DRAFT',
                'created_by' => $this->userId(),
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]));

            $this->addHistory(
                $id,
                'DRAFT',
                'Draft created by Admin'
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                return $this->fail('Could not create expense.', 500);
            }

            $this->db->transCommit();

            $created = $this->findExpense($id);

            return $created
                ? $this->respondJson($this->formatExpense($created), 201)
                : $this->fail(
                    'Expense created but could not be retrieved.',
                    500
                );
        } catch (\InvalidArgumentException $e) {
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }

            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Admin expense creation failed: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->fail(
                'Could not create expense. Check the server log for details.',
                500
            );
        }
    }

    /** PUT /api/admin/expenses/{id} */
    public function update($id = null): ResponseInterface
    {
        if (!$id) {
            return $this->fail('Expense ID is required.', 400);
        }

        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        $id = (string) $id;
        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Admin expense not found.', 404);
        }

        $status = strtoupper((string) $row['status']);

        if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
            return $this->fail(
                'Only draft or rejected Admin expenses can be edited.',
                409
            );
        }

        try {
            $fields = $this->mapInput($this->body());

            $this->db->transBegin();

            $updated = $this->db->table('expenses')
                ->where('id', $id)
                ->where('expense_source', 'ADMIN')
                ->whereIn('status', ['DRAFT', 'REJECTED'])
                ->update(array_merge($fields, [
                    'status' => 'DRAFT',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                    'updated_at' => $this->now(),
                    'version' => ((int) ($row['version'] ?? 1)) + 1,
                ]));

            if ($this->db->transStatus() === false || $updated === false) {
                $this->db->transRollback();
                return $this->fail('Could not update expense.', 500);
            }

            $this->addHistory(
                $id,
                'DRAFT',
                $status === 'REJECTED'
                    ? 'Rejected expense corrected and returned to draft'
                    : 'Draft updated by Admin'
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                return $this->fail('Could not update expense.', 500);
            }

            $this->db->transCommit();

            $updatedRow = $this->findExpense($id);

            return $updatedRow
                ? $this->respondJson($this->formatExpense($updatedRow))
                : $this->fail(
                    'Updated expense could not be retrieved.',
                    500
                );
        } catch (\InvalidArgumentException $e) {
            $this->db->transRollback();
            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Admin expense update failed: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->fail(
                'Could not update expense. Check the server log for details.',
                500
            );
        }
    }

    /** POST /api/admin/expenses/{id}/submit */
    public function submit($id = null): ResponseInterface
    {
        if (!$id) {
            return $this->fail('Expense ID is required.', 400);
        }

        $denied = $this->authorizeAdmin();

        if ($denied) {
            return $denied;
        }

        $id = (string) $id;
        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Admin expense not found.', 404);
        }

        if (!in_array(strtoupper((string) $row['status']), ['DRAFT', 'REJECTED'], true)) {
            return $this->fail(
                'Only draft or rejected expenses can be submitted.',
                409
            );
        }

        if (empty($row['attachment_id'])) {
            return $this->fail(
                'Attach the invoice before submitting this expense for Finance review.',
                422
            );
        }

        $this->db->transBegin();

        $this->db->table('expenses')
            ->where('id', $id)
            ->where('expense_source', 'ADMIN')
            ->whereIn('status', ['DRAFT', 'REJECTED'])
            ->update([
                'status' => 'SUBMITTED',
                'submitted_by' => $this->userId(),
                'submitted_at' => $this->now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'updated_at' => $this->now(),
                'version' => ((int) ($row['version'] ?? 1)) + 1,
            ]);

        if (
            $this->db->transStatus() === false ||
            $this->db->affectedRows() !== 1
        ) {
            $this->db->transRollback();

            return $this->fail(
                'Expense status changed or submission failed. Refresh and try again.',
                409
            );
        }

        $this->addHistory(
            $id,
            'SUBMITTED',
            'Submitted by Admin for Finance review'
        );

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();
            return $this->fail('Could not submit expense.', 500);
        }

        $this->db->transCommit();

        $updated = $this->findExpense($id);

        return $updated
            ? $this->respondJson($this->formatExpense($updated))
            : $this->fail(
                'Submitted expense could not be retrieved.',
                500
            );
    }
}
