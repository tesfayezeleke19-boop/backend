<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class FinanceExpensesController extends BaseController
{
    protected $format = 'json';

    private BaseConnection $db;
    private ?array $authenticatedUser = null;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    private function respondJson(
        array $data,
        int $status = 200
    ): ResponseInterface {
        return $this->response
            ->setStatusCode($status)
            ->setJSON([
                'success' => true,
                'data' => $data,
            ]);
    }

    private function fail(
        string $message,
        int $status = 400
    ): ResponseInterface {
        return $this->response
            ->setStatusCode($status)
            ->setJSON([
                'success' => false,
                'message' => $message,
            ]);
    }

    private function body(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    /**
     * Authenticate through the existing user_sessions table.
     */
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
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if (!$session) {
            return false;
        }

        $user = $this->db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (
            !$user ||
            strtolower($user['account_status'] ?? '') !== 'active'
        ) {
            return false;
        }

        $this->db->table('user_sessions')
            ->where('id', $session['id'])
            ->update([
                'last_used_at' => date('Y-m-d H:i:s'),
            ]);

        $this->authenticatedUser = $user;

        return true;
    }

    private function authorize(array $roles): ?ResponseInterface
    {
        if (!$this->authenticate()) {
            return $this->fail('Authentication required.', 401);
        }

        $role = strtoupper(
            trim($this->authenticatedUser['role'] ?? '')
        );

        if (!in_array($role, $roles, true)) {
            return $this->fail(
                'You do not have permission for this operation.',
                403
            );
        }

        return null;
    }

    private function userId(): string
    {
        return (string) $this->authenticatedUser['id'];
    }

    private function role(): string
    {
        return strtoupper(
            trim($this->authenticatedUser['role'] ?? '')
        );
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

    private function findExpense(string $id): ?array
    {
        return $this->db->table('expenses')
            ->where('id', $id)
            ->get()
            ->getRowArray() ?: null;
    }

    private function history(
        string $expenseId,
        string $status,
        ?string $reason = null
    ): void {
        $this->db->table('expense_status_history')->insert([
            'id' => $this->newUuid(),
            'expense_id' => $expenseId,
            'status' => $status,
            'changed_by' => $this->userId(),
            'reason' => $reason,
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Load attachment metadata using the ID stored on an expense.
     */
    private function formatAttachment(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        $attachment = $this->db->table('attachments')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return null;
        }

        return [
            'id' => $attachment['id'],
            'fileName' => $attachment['file_name'],
            'fileUrl' => '',
            'mimeType' => $attachment['mime_type'],
            'fileSize' => (int) $attachment['file_size'],
        ];
    }

    /**
     * Convert a database record into the frontend expense model.
     */
    
/**
 * Convert a database record into the frontend expense model.
 */
private function formatExpense(array $row): array
{
    $source = strtoupper($row['expense_source'] ?? 'LEGACY');
    $status = strtoupper($row['status'] ?? 'DRAFT');

    $base = (float) $row['amount_excl_vat'];
    $vat = (float) $row['vat_amount'];
    $wht = (float) $row['wht_amount'];
    $gross = (float) $row['total_amount'];

    // Standard or Casual classification.
    // Database column: expense_category.
    $expenseCategory = strtoupper(
        trim((string) ($row['expense_category'] ?? ''))
    );

    // Actual expense type selected from the expense type dropdown.
    // Database column: expense_type.
    $expenseType = $row['expense_type'] ?? '';

    $history = $this->db->table('expense_status_history')
        ->where('expense_id', $row['id'])
        ->orderBy('created_at', 'ASC')
        ->get()
        ->getResultArray();

    return [
        'id' => $row['id'],
        'expenseNumber' => $row['expense_number'],
        'expenseSource' => $source,

        'date' => $row['expense_date'],

        // Actual expense type: Maintenance, Fuel, Rent, etc.
        'type' => $expenseType,

        // Category classification: STANDARD or CASUAL.
        'category' => $expenseCategory !== ''
            ? $expenseCategory
            : null,

        // Compatibility property: also returns the actual expense type.
        // It must not duplicate the category.
        'expenseType' => $expenseType,

        'item' => $row['description'],

        'supplierName' => $row['supplier_name'],
        'supplierTin' => $row['supplier_tin'],

        'amountBeforeVat' => $base,
        'vatEnabled' => (bool) $row['vat_enabled'],
        'vatRate' => (float) $row['vat_rate'],
        'vatAmount' => $vat,

        'whtEnabled' => (bool) $row['wht_enabled'],
        'whtRate' => (float) $row['wht_rate'],
        'whtAmount' => $wht,

        'amount' => $gross,
        'totalTransferred' => round($gross - $wht, 2),

        'bank' => $row['bank'],
        'paymentReference' => $row['payment_reference'],
        'paymentParty' => $row['payment_party'],
        'remark' => $row['remark'],

        'status' => $status,
        'reviewStatus' => $status,

        'isCounted' => (
            $source !== 'ADMIN' || $status === 'APPROVED'
        ),

        'submittedBy' => $row['submitted_by'],
        'submittedAt' => $row['submitted_at'],
        'reviewedBy' => $row['reviewed_by'],
        'reviewedAt' => $row['reviewed_at'],
        'rejectionReason' => $row['rejection_reason'],

        'invoiceAttachmentId' => $row['attachment_id'],
        'whtAttachmentId' => $row['wht_attachment_id'],

        'invoiceAttachment' => $this->formatAttachment(
            $row['attachment_id'] ?? null
        ),

        'whtAttachment' => $this->formatAttachment(
            $row['wht_attachment_id'] ?? null
        ),

        'createdBy' => $row['created_by'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
        'version' => (int) $row['version'],

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
     * Validate and map frontend input to database columns.
     */
    private function mapInput(array $input): array
    {
        $date = $input['date'] ?? $input['expenseDate'] ?? null;

        // Ordinary expense dropdown selection.
        $expenseType = trim(
            (string) (
                $input['type']
                ?? $input['expense_type']
                ?? $input['category']
                ?? ''
            )
        );

        $description = trim(
            (string) (
                $input['item'] ??
                $input['description'] ??
                ''
            )
        );

        $base = $input['amountBeforeVat']
            ?? $input['amount_excl_vat']
            ?? null;

        if (
            !$date ||
            !$expenseType ||
            !$description ||
            !is_numeric($base)
        ) {
            throw new \InvalidArgumentException(
                'Date, expense type, description, and amount before VAT are required.'
            );
        }

        // Validate the date strictly.
        $parsedDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            (string) $date
        );

        if (
            !$parsedDate ||
            $parsedDate->format('Y-m-d') !== (string) $date
        ) {
            throw new \InvalidArgumentException(
                'Expense date must be a valid date in YYYY-MM-DD format.'
            );
        }

        $base = (float) $base;

        if (!is_finite($base) || $base < 0) {
            throw new \InvalidArgumentException(
                'Expense amount must be a valid non-negative number.'
            );
        }

        /*
         * Standard/Casual classification.
         *
         * Database column: expense_category.
         *
         * Supported frontend field names:
         * expenseCategory
         * expense_category
         * expenseType (existing frontend compatibility)
         */
        $expenseCategory = strtoupper(
            trim((string) (
                $input['expenseCategory']
                ?? $input['expense_category']
                ?? $input['expenseType']
                ?? 'STANDARD'
            ))
        );

        if (!in_array($expenseCategory, ['STANDARD', 'CASUAL'], true)) {
            throw new \InvalidArgumentException(
                'Expense category must be either STANDARD or CASUAL.'
            );
        }

        // Standard expenses have VAT enabled by default.
        // Casual expenses have VAT disabled by default.
        $vatEnabled = array_key_exists('vatEnabled', $input)
            ? filter_var(
                $input['vatEnabled'],
                FILTER_VALIDATE_BOOLEAN
            )
            : ($expenseCategory === 'STANDARD');

        $whtEnabled = filter_var(
            $input['whtEnabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $vatRate = $input['vatRate'] ?? 0.15;
        $whtRate = $input['whtRate'] ?? 0.03;

        if (!is_numeric($vatRate) || !is_numeric($whtRate)) {
            throw new \InvalidArgumentException(
                'Tax rates must be numeric.'
            );
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
                'Tax rates must be between 0 and 1.'
            );
        }

        $vat = $vatEnabled
            ? round($base * $vatRate, 2)
            : 0.0;

        $wht = $whtEnabled
            ? round(($base + $vat) * $whtRate, 2)
            : 0.0;

        $gross = round($base + $vat, 2);

        return [
            'expense_date' => (string) $date,

            // Ordinary expense dropdown selection.
            'expense_type' => $expenseType,

            // Standard or Casual classification.
            'expense_category' => $expenseCategory,

            'description' => $description,

            'supplier_name' => $input['supplierName'] ?? null,
            'supplier_tin' => $input['supplierTin'] ?? null,

            'amount_excl_vat' => round($base, 2),
            'vat_enabled' => $vatEnabled,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,

            'wht_enabled' => $whtEnabled,
            'wht_rate' => $whtRate,
            'wht_amount' => $wht,

            'total_amount' => $gross,

            'bank' => $input['bank'] ?? null,
            'payment_reference' => $input['paymentReference'] ?? null,
            'payment_party' => $input['paymentParty'] ?? null,

            'remark' => $input['remark']
                ?? $input['remarkText']
                ?? null,

            'attachment_id' => $input['invoiceAttachmentId'] ?? null,
            'wht_attachment_id' => $input['whtAttachmentId'] ?? null,

            'updated_at' => $this->now(),
        ];
    }

    private function validateAttachment(?string $id): bool
    {
        if ($id === null || $id === '') {
            return true;
        }

        return $this->db->table('attachments')
            ->where('id', $id)
            ->countAllResults() > 0;
    }

    private function readAll(
        ?string $source = null,
        ?string $status = null
    ): array {
        $builder = $this->db->table('expenses');

        if ($source !== null) {
            $builder->where('expense_source', $source);
        }

        if ($status !== null) {
            $builder->where('status', $status);
        }

        $rows = $builder
            ->orderBy('expense_date', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(
            fn(array $row): array => $this->formatExpense($row),
            $rows
        );
    }

    /**
     * POST /api/finance/expenses/attachments/upload
     */
    public function uploadAttachment(): ResponseInterface
    {
        $denied = $this->authorize(['ADMIN', 'FINANCE']);

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
                'Finance expense attachment upload failed: {message}',
                [
                    'message' => $e->getMessage(),
                ]
            );

            return $this->fail(
                'File upload failed. Check the server log.',
                500
            );
        }
    }

    /**
     * GET /api/finance/expenses/attachments/{id}
     */
    public function viewAttachment($id = null): ResponseInterface
    {
        if (
            $id === null ||
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                (string) $id
            )
        ) {
            return $this->fail('Attachment not found.', 404);
        }

        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $attachment = $this->db->table('attachments')
            ->select('id, storage_key, mime_type, file_name')
            ->where('id', (string) $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->fail('Attachment not found.', 404);
        }

        $storageKey = (string) ($attachment['storage_key'] ?? '');

        if (!preg_match(
            '/^file\/expense\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(pdf|jpg|png|webp)$/i',
            $storageKey
        )) {
            log_message(
                'error',
                'Invalid expense attachment storage key: ' . $storageKey
            );

            return $this->fail(
                'Invalid attachment storage path.',
                404
            );
        }

        $directory = ROOTPATH
            . 'file'
            . DIRECTORY_SEPARATOR
            . 'expense';

        $filePath = $directory
            . DIRECTORY_SEPARATOR
            . basename($storageKey);

        if (!is_file($filePath) || !is_readable($filePath)) {
            log_message(
                'error',
                'Expense attachment file missing: ' . $filePath
            );

            return $this->fail(
                'Attachment file not found on the server.',
                404
            );
        }

        $mimeType = (string) ($attachment['mime_type'] ?? '');

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

        $content = file_get_contents($filePath);

        if ($content === false) {
            log_message(
                'error',
                'Unable to read expense attachment: ' . $filePath
            );

            return $this->fail(
                'Unable to read attachment.',
                500
            );
        }

        $fileName = basename(
            str_replace(
                ["\r", "\n", '"'],
                '',
                (string) ($attachment['file_name'] ?? 'attachment')
            )
        );

        log_message(
            'debug',
            'Expense attachment original size: ' . strlen($content)
        );

        log_message(
            'debug',
            'Expense attachment original SHA256: ' . hash('sha256', $content)
        );

        log_message(
            'debug',
            'Expense attachment original first bytes: '
            . bin2hex(substr($content, 0, 8))
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

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
     * GET /api/finance/expenses
     */
    public function index(): ResponseInterface
    {
        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $source = strtoupper(
            (string) $this->request->getGet('source')
        );

        $status = strtoupper(
            (string) $this->request->getGet('status')
        );

        return $this->respondJson(
            $this->readAll(
                in_array(
                    $source,
                    ['ADMIN', 'FINANCE', 'LEGACY'],
                    true
                ) ? $source : null,
                $status !== '' ? $status : null
            )
        );
    }

    /**
     * GET /api/finance/expenses/{id}
     */
    public function show($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense((string) $id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        return $this->respondJson($this->formatExpense($row));
    }

    /**
     * POST /api/finance/expenses
     */
    public function create(): ResponseInterface
    {
        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $input = $this->body();
        $role = $this->role();

        $source = $role === 'FINANCE' ? 'FINANCE' : 'ADMIN';
        $status = $source === 'FINANCE' ? 'APPROVED' : 'DRAFT';

        try {
            $fields = $this->mapInput($input);

            if (
                !$this->validateAttachment($fields['attachment_id']) ||
                !$this->validateAttachment($fields['wht_attachment_id'])
            ) {
                return $this->fail(
                    'One of the supplied attachments does not exist.',
                    422
                );
            }

            $id = $this->newUuid();

            $this->db->transBegin();

            $this->db->table('expenses')->insert(
                array_merge($fields, [
                    'id' => $id,
                    'expense_number' => $input['expenseNumber'] ?? null,
                    'expense_source' => $source,
                    'status' => $status,
                    'created_by' => $this->userId(),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                    'version' => 1,
                ])
            );

            $this->history(
                $id,
                $status,
                $source === 'FINANCE'
                    ? 'Finance expense created'
                    : 'Admin expense created'
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->fail(
                    'Could not create expense.',
                    500
                );
            }

            $this->db->transCommit();

            $created = $this->findExpense($id);

            if (!$created) {
                return $this->fail(
                    'Expense was created but could not be retrieved.',
                    500
                );
            }

            return $this->respondJson(
                $this->formatExpense($created),
                201
            );
        } catch (\InvalidArgumentException $e) {
            $this->db->transRollback();

            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Expense creation failed: ' . $e->getMessage()
            );

            return $this->fail(
                'Could not create expense.',
                500
            );
        }
    }

    /**
     * PUT /api/finance/expenses/{id}
     */
    public function update($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $id = (string) $id;

        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        $source = strtoupper($row['expense_source'] ?? 'LEGACY');
        $status = strtoupper($row['status'] ?? '');

        if ($source === 'ADMIN') {
            if ($this->role() !== 'ADMIN') {
                return $this->fail(
                    'Only Admin can edit this expense.',
                    403
                );
            }

            if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
                return $this->fail(
                    'Only draft or rejected expenses can be edited.',
                    409
                );
            }
        } elseif ($this->role() !== 'FINANCE') {
            return $this->fail(
                'Only Finance can edit Finance or legacy expenses.',
                403
            );
        }

        try {
            $fields = $this->mapInput($this->body());

            if (
                !$this->validateAttachment($fields['attachment_id']) ||
                !$this->validateAttachment($fields['wht_attachment_id'])
            ) {
                return $this->fail(
                    'One of the supplied attachments does not exist.',
                    422
                );
            }

            $this->db->transBegin();

            $this->db->table('expenses')
                ->where('id', $id)
                ->update(
                    array_merge($fields, [
                        'version' => ((int) $row['version']) + 1,
                    ])
                );

            if ($source === 'ADMIN' && $status === 'REJECTED') {
                $this->db->table('expenses')
                    ->where('id', $id)
                    ->update([
                        'status' => 'DRAFT',
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                        'rejection_reason' => null,
                    ]);

                $this->history(
                    $id,
                    'DRAFT',
                    'Rejected expense edited'
                );
            } else {
                $this->history($id, $status, 'Expense updated');
            }

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->fail(
                    'Could not update expense.',
                    500
                );
            }

            $this->db->transCommit();

            $updated = $this->findExpense($id);

            return $updated
                ? $this->respondJson($this->formatExpense($updated))
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
                'Expense update failed: ' . $e->getMessage()
            );

            return $this->fail(
                'Could not update expense.',
                500
            );
        }
    }

    /**
     * POST /api/finance/expenses/{id}/submit
     */
    public function submit($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $id = (string) $id;

        $denied = $this->authorize(['ADMIN']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        if (
            strtoupper($row['expense_source'] ?? '') !== 'ADMIN' ||
            !in_array(
                strtoupper($row['status'] ?? ''),
                ['DRAFT', 'REJECTED'],
                true
            )
        ) {
            return $this->fail(
                'This expense cannot be submitted.',
                409
            );
        }

        $this->db->transBegin();

        $this->db->table('expenses')
            ->where('id', $id)
            ->whereIn('status', ['DRAFT', 'REJECTED'])
            ->update([
                'status' => 'SUBMITTED',
                'submitted_by' => $this->userId(),
                'submitted_at' => $this->now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'updated_at' => $this->now(),
                'version' => ((int) $row['version']) + 1,
            ]);

        if (
            $this->db->transStatus() === false ||
            $this->db->affectedRows() !== 1
        ) {
            $this->db->transRollback();

            return $this->fail(
                'Could not submit expense. Refresh and try again.',
                409
            );
        }

        $this->history(
            $id,
            'SUBMITTED',
            'Submitted for Finance review'
        );

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return $this->fail(
                'Could not submit expense.',
                500
            );
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

    /**
     * GET /api/finance/expenses/review-queue
     */
    public function reviewQueue(): ResponseInterface
    {
        $denied = $this->authorize(['FINANCE']);

        if ($denied) {
            return $denied;
        }

        return $this->respondJson(
            $this->readAll('ADMIN', 'SUBMITTED')
        );
    }

    /**
     * POST /api/finance/expenses/{id}/approve
     */
    public function approve($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $id = (string) $id;

        $denied = $this->authorize(['FINANCE']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        if (
            strtoupper($row['expense_source'] ?? '') !== 'ADMIN' ||
            strtoupper($row['status'] ?? '') !== 'SUBMITTED'
        ) {
            return $this->fail(
                'Only submitted Admin expenses can be approved.',
                409
            );
        }

        if (($row['submitted_by'] ?? null) === $this->userId()) {
            return $this->fail(
                'You cannot approve your own submission.',
                403
            );
        }

        $this->db->transBegin();

        $this->db->table('expenses')
            ->where('id', $id)
            ->where('status', 'SUBMITTED')
            ->update([
                'status' => 'APPROVED',
                'reviewed_by' => $this->userId(),
                'reviewed_at' => $this->now(),
                'rejection_reason' => null,
                'updated_at' => $this->now(),
                'version' => ((int) $row['version']) + 1,
            ]);

        if (
            $this->db->transStatus() === false ||
            $this->db->affectedRows() !== 1
        ) {
            $this->db->transRollback();

            return $this->fail(
                'Expense status changed; refresh and try again.',
                409
            );
        }

        $this->history(
            $id,
            'APPROVED',
            'Approved by Finance'
        );

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return $this->fail(
                'Could not approve expense.',
                500
            );
        }

        $this->db->transCommit();

        $updated = $this->findExpense($id);

        return $updated
            ? $this->respondJson($this->formatExpense($updated))
            : $this->fail(
                'Approved expense could not be retrieved.',
                500
            );
    }

    /**
     * POST /api/finance/expenses/{id}/reject
     */
    public function reject($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $id = (string) $id;

        $denied = $this->authorize(['FINANCE']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        if (
            strtoupper($row['expense_source'] ?? '') !== 'ADMIN' ||
            strtoupper($row['status'] ?? '') !== 'SUBMITTED'
        ) {
            return $this->fail(
                'Only submitted Admin expenses can be rejected.',
                409
            );
        }

        if (($row['submitted_by'] ?? null) === $this->userId()) {
            return $this->fail(
                'You cannot reject your own submission.',
                403
            );
        }

        $input = $this->body();

        $reason = trim(
            (string) (
                $input['reason'] ??
                $input['rejectionReason'] ??
                ''
            )
        );

        if ($reason === '') {
            return $this->fail(
                'A rejection reason is required.',
                422
            );
        }

        $this->db->transBegin();

        $this->db->table('expenses')
            ->where('id', $id)
            ->where('status', 'SUBMITTED')
            ->update([
                'status' => 'REJECTED',
                'reviewed_by' => $this->userId(),
                'reviewed_at' => $this->now(),
                'rejection_reason' => $reason,
                'updated_at' => $this->now(),
                'version' => ((int) $row['version']) + 1,
            ]);

        if (
            $this->db->transStatus() === false ||
            $this->db->affectedRows() !== 1
        ) {
            $this->db->transRollback();

            return $this->fail(
                'Expense status changed; refresh and try again.',
                409
            );
        }

        $this->history($id, 'REJECTED', $reason);

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return $this->fail(
                'Could not reject expense.',
                500
            );
        }

        $this->db->transCommit();

        $updated = $this->findExpense($id);

        return $updated
            ? $this->respondJson($this->formatExpense($updated))
            : $this->fail(
                'Rejected expense could not be retrieved.',
                500
            );
    }

    /**
     * DELETE /api/finance/expenses/{id}
     */
    public function delete($id = null)
    {
        if ($id === null || $id === '') {
            return $this->fail('Expense ID is required.', 400);
        }

        $id = (string) $id;

        $denied = $this->authorize(['ADMIN', 'FINANCE']);

        if ($denied) {
            return $denied;
        }

        $row = $this->findExpense($id);

        if (!$row) {
            return $this->fail('Expense not found.', 404);
        }

        $source = strtoupper($row['expense_source'] ?? 'LEGACY');
        $status = strtoupper($row['status'] ?? '');

        if ($source === 'ADMIN') {
            if ($this->role() !== 'ADMIN') {
                return $this->fail(
                    'Only Admin can delete Admin expenses.',
                    403
                );
            }

            if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
                return $this->fail(
                    'Only draft or rejected Admin expenses can be deleted.',
                    409
                );
            }
        } elseif ($this->role() !== 'FINANCE') {
            return $this->fail(
                'Only Finance can delete Finance or legacy expenses.',
                403
            );
        }

        $this->db->transBegin();

        $this->db->table('expense_status_history')
            ->where('expense_id', $id)
            ->delete();

        $this->db->table('expenses')
            ->where('id', $id)
            ->delete();

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return $this->fail(
                'Could not delete expense.',
                500
            );
        }

        $this->db->transCommit();

        return $this->respondJson([
            'id' => $id,
            'deleted' => true,
        ]);
    }
}