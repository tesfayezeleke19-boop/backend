<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

class FinanceMonthlyInventoryReportsController extends ResourceController
{
    protected $format = 'json';

    private $db;

    private const REPORT_TABLE = 'monthly_inventory_reports';
    private const REVISION_TABLE = 'monthly_inventory_report_revisions';
    private const LINE_TABLE = 'monthly_inventory_report_lines';
    private const AGGREGATE_TABLE = 'aggregate_types';

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/finance/aggregate-types
    |--------------------------------------------------------------------------
    | Finance and Admin can retrieve active aggregate types.
    */
    public function aggregateTypes(): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        if (!$this->canReadReports($auth['user'])) {
            return $this->respondError('Access denied.', 403);
        }

        try {
            $types = $this->getActiveAggregateTypes();

            return $this->respond([
                'success' => true,
                'aggregateTypes' => array_values($types),
                'data' => array_values($types),
            ]);
        } catch (Throwable $e) {
            log_message(
                'error',
                'Aggregate type retrieval failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to retrieve aggregate types.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/finance/monthly-inventory-reports
    |--------------------------------------------------------------------------
    */
    public function index(): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        if (!$this->canReadReports($auth['user'])) {
            return $this->respondError('Access denied.', 403);
        }

        try {
            $rows = $this->db->table(self::REPORT_TABLE)
                ->orderBy('report_month', 'DESC')
                ->orderBy('created_at', 'DESC')
                ->get()
                ->getResultArray();

            $reports = array_map(
                fn(array $row): array => $this->serializeReport($row),
                $rows
            );

            return $this->respond([
                'success' => true,
                'reports' => $reports,
                'data' => $reports,
            ]);
        } catch (Throwable $e) {
            log_message(
                'error',
                'Monthly inventory report listing failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to retrieve monthly inventory reports.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/finance/monthly-inventory-reports/{period}
    |--------------------------------------------------------------------------
    */
    public function showPeriod(string $period): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        if (!$this->canReadReports($auth['user'])) {
            return $this->respondError('Access denied.', 403);
        }

        if (!$this->isValidPeriod($period)) {
            return $this->respondError(
                'Invalid Ethiopian report period. Use YYYY-MM, with month 01-13.',
                422
            );
        }

        try {
            $report = $this->findReportByPeriod($period);

            if (!$report) {
                $report = $this->findLegacyReportByPeriod($period);
            }

            if (!$report) {
                return $this->respond([
                    'success' => true,
                    'report' => null,
                    'data' => null,
                    'message' => 'No report exists for this period.',
                ]);
            }

            $serialized = $this->serializeReport($report);

            return $this->respond([
                'success' => true,
                'report' => $serialized,
                'data' => $serialized,
            ]);
        } catch (Throwable $e) {
            log_message(
                'error',
                'Monthly inventory report retrieval failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to retrieve the requested report.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/finance/monthly-inventory-reports/draft
    |--------------------------------------------------------------------------
    */
    public function saveDraft(): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        $user = $auth['user'];

        if (!$this->canWriteReports($user)) {
            return $this->respondError(
                'Only authorized finance users can save drafts.',
                403
            );
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }

        $validation = $this->validatePayload($payload);

        if (!$validation['ok']) {
            return $this->respondError(
                $validation['message'],
                422
            );
        }

        try {
            $this->db->transBegin();

            $period = $validation['period'];
            $existing = $this->findReportByPeriod($period);

            if (
                $existing &&
                strtoupper((string) $existing['status']) === 'SUBMITTED'
            ) {
                $this->db->transRollback();

                return $this->respondError(
                    'This report has already been submitted. Use the correction workflow instead.',
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            $data = [
                'period' => $period,
                'report_month' => $this->ethiopianPeriodStartDate($period),
                'status' => 'DRAFT',

                // Retained only for backward compatibility.
                // Dynamic quantities are stored in report lines.
                'aggregate_00' => 0,
                'aggregate_01' => 0,
                'aggregate_02' => 0,

                'remarks' => $validation['remarks'],
                'updated_by' => $user['id'],
                'updated_at' => $now,
            ];

            if ($existing) {
                $data['version'] =
                    ((int) ($existing['version'] ?? 1)) + 1;

                $this->db->table(self::REPORT_TABLE)
                    ->where('id', $existing['id'])
                    ->update($data);

                $reportId = $existing['id'];
            } else {
                $data['created_by'] = $user['id'];
                $data['created_at'] = $now;
                $data['version'] = 1;
                $data['revision_number'] = 0;

                $this->db->table(self::REPORT_TABLE)->insert($data);

                $created = $this->findReportByPeriod($period);
                $reportId = $created['id'] ?? null;
            }

            if (!$reportId) {
                throw new \RuntimeException(
                    'Could not identify the saved inventory report.'
                );
            }

            $this->saveReportLines(
                $reportId,
                $validation['lines']
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->respondError(
                    'Unable to save the report draft.',
                    500
                );
            }

            $this->db->transCommit();

            $report = $this->findReportByPeriod($period);

            $serialized = $report
                ? $this->serializeReport($report)
                : null;

            return $this->respond([
                'success' => true,
                'message' => 'Draft saved successfully.',
                'report' => $serialized,
                'data' => $serialized,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Monthly inventory draft save failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to save the report draft. Check the backend logs.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/finance/monthly-inventory-reports/submit
    |--------------------------------------------------------------------------
    */
    public function submitReport(): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        $user = $auth['user'];

        if (!$this->canWriteReports($user)) {
            return $this->respondError(
                'Only authorized finance users can submit reports.',
                403
            );
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }

        $validation = $this->validatePayload($payload);

        if (!$validation['ok']) {
            return $this->respondError(
                $validation['message'],
                422
            );
        }

        try {
            $this->db->transBegin();

            $result = $this->persistSubmission(
                $validation,
                $user
            );

            if (!$result['ok']) {
                $this->db->transRollback();

                return $this->respondError(
                    $result['message'],
                    $result['status']
                );
            }

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->respondError(
                    'Unable to submit the report.',
                    500
                );
            }

            $this->db->transCommit();

            $report = $this->findReportByPeriod(
                $validation['period']
            );

            $serialized = $report
                ? $this->serializeReport($report)
                : null;

            return $this->respond([
                'success' => true,
                'message' => 'Report submitted successfully.',
                'report' => $serialized,
                'data' => $serialized,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Monthly inventory report submission failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to submit the report. Check the backend logs.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/finance/monthly-inventory-reports/correct
    |--------------------------------------------------------------------------
    */
    public function correct(): ResponseInterface
    {
        $auth = $this->authenticate();

        if (!$auth['ok']) {
            return $this->respondError(
                $auth['message'],
                $auth['status']
            );
        }

        $user = $auth['user'];

        if (!$this->canWriteReports($user)) {
            return $this->respondError(
                'Only authorized finance users can correct reports.',
                403
            );
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }

        $validation = $this->validatePayload($payload);

        if (!$validation['ok']) {
            return $this->respondError(
                $validation['message'],
                422
            );
        }

        try {
            $this->db->transBegin();

            $existing = $this->findReportByPeriod(
                $validation['period']
            );

            if (!$existing) {
                $this->db->transRollback();

                return $this->respondError(
                    'Cannot correct a report that does not exist.',
                    404
                );
            }

            if (
                strtoupper((string) $existing['status']) !== 'SUBMITTED'
            ) {
                $this->db->transRollback();

                return $this->respondError(
                    'Only submitted reports can be corrected. Save or submit the draft first.',
                    409
                );
            }

            $revisionNumber =
                ((int) ($existing['revision_number'] ?? 0)) + 1;

            // Capture the existing report lines before changing them.
            $previousLines = $this->getReportLines($existing['id']);

            $revisionData = [
                'report_id' => $existing['id'],
                'revision_number' => $revisionNumber,
                'period' => $validation['period'],

                // Legacy compatibility fields.
                'aggregate_00' => $existing['aggregate_00'] ?? 0,
                'aggregate_01' => $existing['aggregate_01'] ?? 0,
                'aggregate_02' => $existing['aggregate_02'] ?? 0,

                // Full dynamic historical snapshot.
                'aggregate_lines' => json_encode(
                    $previousLines,
                    JSON_THROW_ON_ERROR
                ),

                'remarks' => $existing['remarks'] ?? null,
                'submitted_at' => $existing['submitted_at']
                    ?? date('Y-m-d H:i:s'),
                'submitted_by' => $existing['submitted_by'] ?? null,
                'submitted_by_name' => $existing['submitted_by_name'] ?? null,
            ];

            $this->db->table(self::REVISION_TABLE)
                ->insert($revisionData);

            $now = date('Y-m-d H:i:s');

            $updateData = [
                'period' => $validation['period'],
                'report_month' => $this->ethiopianPeriodStartDate(
                    $validation['period']
                ),
                'status' => 'SUBMITTED',

                // Dynamic values are stored in report lines.
                'aggregate_00' => 0,
                'aggregate_01' => 0,
                'aggregate_02' => 0,

                'remarks' => $validation['remarks'],
                'submitted_by' => $user['id'],
                'submitted_by_name' => $user['name'] ?? null,
                'submitted_at' => $now,
                'revision_number' => $revisionNumber,
                'version' => ((int) ($existing['version'] ?? 1)) + 1,
                'updated_by' => $user['id'],
                'updated_at' => $now,
            ];

            $this->db->table(self::REPORT_TABLE)
                ->where('id', $existing['id'])
                ->update($updateData);

            $this->saveReportLines(
                $existing['id'],
                $validation['lines']
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                return $this->respondError(
                    'Unable to correct the report.',
                    500
                );
            }

            $this->db->transCommit();

            $report = $this->findReportByPeriod(
                $validation['period']
            );

            $serialized = $report
                ? $this->serializeReport($report)
                : null;

            return $this->respond([
                'success' => true,
                'message' => 'Report corrected successfully.',
                'report' => $serialized,
                'data' => $serialized,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            log_message(
                'error',
                'Monthly inventory report correction failed: ' . $e->getMessage()
            );

            return $this->respondError(
                'Unable to correct the report. Check the backend logs.',
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Persist a submitted report
    |--------------------------------------------------------------------------
    */
    private function persistSubmission(
        array $validation,
        array $user
    ): array {
        $period = $validation['period'];
        $existing = $this->findReportByPeriod($period);

        if ($existing) {
            if (
                strtoupper((string) $existing['status']) === 'SUBMITTED'
            ) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'message' => 'This report has already been submitted. Use the correction workflow.',
                ];
            }
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'period' => $period,
            'report_month' => $this->ethiopianPeriodStartDate($period),
            'status' => 'SUBMITTED',

            // Retained for compatibility with old database columns.
            'aggregate_00' => 0,
            'aggregate_01' => 0,
            'aggregate_02' => 0,

            'remarks' => $validation['remarks'],
            'submitted_by' => $user['id'],
            'submitted_by_name' => $user['name'] ?? null,
            'submitted_at' => $now,
            'updated_by' => $user['id'],
            'updated_at' => $now,
        ];

        if ($existing) {
            $data['version'] =
                ((int) ($existing['version'] ?? 1)) + 1;

            $this->db->table(self::REPORT_TABLE)
                ->where('id', $existing['id'])
                ->update($data);

            $reportId = $existing['id'];
        } else {
            $data['created_by'] = $user['id'];
            $data['created_at'] = $now;
            $data['version'] = 1;
            $data['revision_number'] = 0;

            $this->db->table(self::REPORT_TABLE)->insert($data);

            $created = $this->findReportByPeriod($period);
            $reportId = $created['id'] ?? null;
        }

        if (!$reportId) {
            throw new \RuntimeException(
                'Could not identify the submitted inventory report.'
            );
        }

        $this->saveReportLines(
            $reportId,
            $validation['lines']
        );

        return ['ok' => true];
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve active aggregate types
    |--------------------------------------------------------------------------
    */
    private function getActiveAggregateTypes(): array
    {
        $rows = $this->db->table(self::AGGREGATE_TABLE)
            ->where('is_active', true)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $types = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            $types[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'pricePerM3' => (float) $row['price_per_m3'],
                'description' => (string) ($row['description'] ?? ''),
                'isActive' => (bool) $row['is_active'],
            ];
        }

        return $types;
    }

    /*
    |--------------------------------------------------------------------------
    | Save dynamic report lines
    |--------------------------------------------------------------------------
    */
    private function saveReportLines(
        string $reportId,
        array $lines
    ): void {
        // Replace the current report lines inside the surrounding transaction.
        $this->db->table(self::LINE_TABLE)
            ->where('report_id', $reportId)
            ->delete();

        foreach ($lines as $line) {
            $this->db->table(self::LINE_TABLE)->insert([
                'report_id' => $reportId,
                'aggregate_type_id' => $line['aggregateTypeId'],
                'aggregate_name' => $line['aggregateName'],

                // New dynamic records do not use legacy codes.
                'aggregate' => null,

                'unsold_quantity' => $line['quantity'],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve report lines
    |--------------------------------------------------------------------------
    */
    private function getReportLines(string $reportId): array
    {
        $rows = $this->db->table(self::LINE_TABLE)
            ->where('report_id', $reportId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(
            function (array $row): array {
                $typeId = $row['aggregate_type_id'] ?? null;

                return [
                    'id' => (string) $row['id'],
                    'aggregateTypeId' => $typeId !== null
                        ? (string) $typeId
                        : null,
                    'aggregateName' => (string) (
                        $row['aggregate_name']
                        ?? $row['aggregate']
                        ?? 'Unknown aggregate'
                    ),
                    'quantity' => (float) $row['unsold_quantity'],
                    'isLegacy' => $typeId === null,
                ];
            },
            $rows
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic payload validation
    |--------------------------------------------------------------------------
    | Accepted format:
    |
    | {
    |   "period": "2019-02",
    |   "quantities": [
    |     {"aggregateTypeId": "1", "quantity": 12},
    |     {"aggregateTypeId": "2", "quantity": 15}
    |   ],
    |   "remarks": ""
    | }
    |
    | An object keyed by aggregate type ID is also accepted:
    | "quantities": {"1": 12, "2": 15}
    */
    private function validatePayload(array $payload): array
    {
        $period = trim((string) ($payload['period'] ?? ''));

        if (!$this->isValidPeriod($period)) {
            return [
                'ok' => false,
                'message' => 'A valid Ethiopian period in YYYY-MM format is required. Month must be 01-13.',
            ];
        }

        // Historical Gregorian records cannot be modified.
        if ($this->findLegacyReportByPeriod($period)) {
            return [
                'ok' => false,
                'message' => 'This is a historical Gregorian report and cannot be changed through the Ethiopian reporting workflow.',
            ];
        }

        $activeTypes = $this->getActiveAggregateTypes();

        if (empty($activeTypes)) {
            return [
                'ok' => false,
                'message' => 'No active aggregate types are configured. Ask an administrator to activate an aggregate type.',
            ];
        }

        $quantities = $payload['quantities'] ?? null;

        if (!is_array($quantities)) {
            return [
                'ok' => false,
                'message' => 'Aggregate quantities must be provided as an array or object.',
            ];
        }

        $submitted = [];

        foreach ($quantities as $key => $item) {
            if (is_array($item)) {
                $id = trim((string) (
                    $item['aggregateTypeId']
                    ?? $item['aggregate_type_id']
                    ?? $item['id']
                    ?? ''
                ));

                $value = $item['quantity']
                    ?? $item['unsoldQuantity']
                    ?? $item['unsold_quantity']
                    ?? null;
            } else {
                $id = trim((string) $key);
                $value = $item;
            }

            if ($id === '' || !ctype_digit($id) || (int) $id < 1) {
                return [
                    'ok' => false,
                    'message' => 'Each inventory line must contain a valid aggregate type ID.',
                ];
            }

            if (array_key_exists($id, $submitted)) {
                return [
                    'ok' => false,
                    'message' => "Aggregate type {$id} was submitted more than once.",
                ];
            }

            if (!isset($activeTypes[$id])) {
                return [
                    'ok' => false,
                    'message' => "Aggregate type {$id} does not exist or is inactive.",
                ];
            }

            if (
                !is_numeric($value) ||
                !is_finite((float) $value) ||
                (float) $value < 0
            ) {
                return [
                    'ok' => false,
                    'message' => "Quantity for {$activeTypes[$id]['name']} must be a non-negative number.",
                ];
            }

            $quantity = (float) $value;

            if ($quantity > 99999999999.999) {
                return [
                    'ok' => false,
                    'message' => "Quantity for {$activeTypes[$id]['name']} is too large.",
                ];
            }

            $submitted[$id] = $quantity;
        }

        // Every currently active aggregate type must have a quantity.
        foreach ($activeTypes as $id => $type) {
            if (!array_key_exists($id, $submitted)) {
                return [
                    'ok' => false,
                    'message' => "A quantity is required for {$type['name']}.",
                ];
            }
        }

        $normalizedLines = [];

        foreach ($activeTypes as $id => $type) {
            $normalizedLines[] = [
                'aggregateTypeId' => $id,
                'aggregateName' => $type['name'],
                'quantity' => $submitted[$id],
            ];
        }

        $remarks = $payload['remarks'] ?? null;

        if ($remarks !== null && !is_string($remarks)) {
            return [
                'ok' => false,
                'message' => 'Remarks must be text.',
            ];
        }

        if ($remarks !== null && strlen($remarks) > 20000) {
            return [
                'ok' => false,
                'message' => 'Remarks cannot exceed 20,000 characters.',
            ];
        }

        return [
            'ok' => true,
            'period' => $period,
            'lines' => $normalizedLines,
            'remarks' => $remarks,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Find a report by Ethiopian YYYY-MM period
    |--------------------------------------------------------------------------
    */
    private function findReportByPeriod(string $period): ?array
    {
        $startDate = $this->ethiopianPeriodStartDate($period);

        if ($startDate === null) {
            return null;
        }

        $row = $this->db->table(self::REPORT_TABLE)
            ->where('report_month', $startDate)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | Find historical Gregorian report by original period
    |--------------------------------------------------------------------------
    */
    private function findLegacyReportByPeriod(string $period): ?array
    {
        $row = $this->db->table(self::REPORT_TABLE)
            ->where('period', $period)
            ->where('report_month', $period . '-01')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Ethiopian reporting period
    |--------------------------------------------------------------------------
    */
    private function isValidPeriod(string $period): bool
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-3])$/', $period)) {
            return false;
        }

        $year = (int) substr($period, 0, 4);

        return $year >= 2000
            && $year <= 2100
            && $this->ethiopianPeriodStartDate($period) !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Convert Ethiopian YYYY-MM to Gregorian first day
    |--------------------------------------------------------------------------
    */
    private function ethiopianPeriodStartDate(string $period): ?string
    {
        if (
            !preg_match(
                '/^(\d{4})-(0[1-9]|1[0-3])$/',
                $period,
                $matches
            )
        ) {
            return null;
        }

        if (!class_exists(\IntlCalendar::class)) {
            log_message(
                'error',
                'PHP intl extension is required for Ethiopian reporting periods.'
            );

            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        try {
            $calendar = \IntlCalendar::createInstance(
                'UTC',
                'en_US@calendar=ethiopic'
            );

            if (!$calendar || $calendar->getType() !== 'ethiopic') {
                return null;
            }

            $calendar->clear();

            $calendar->set(
                $year,
                $month - 1,
                1,
                0,
                0,
                0
            );

            $milliseconds = $calendar->getTime();

            if ($milliseconds === false) {
                return null;
            }

            return gmdate(
                'Y-m-d',
                (int) floor($milliseconds / 1000)
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                'Ethiopian period conversion failed: ' . $e->getMessage()
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */
    private function authenticate(): array
    {
        $header = $this->request->getHeaderLine('Authorization');

        if (
            !$header ||
            !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)
        ) {
            return [
                'ok' => false,
                'status' => 401,
                'message' => 'Authentication token is required.',
            ];
        }

        $token = trim($matches[1]);

        if ($token === '') {
            return [
                'ok' => false,
                'status' => 401,
                'message' => 'Authentication token is invalid.',
            ];
        }

        try {
            $tokenHash = hash('sha256', $token);

            $session = $this->db->table('user_sessions')
                ->where('token_hash', $tokenHash)
                ->where('revoked_at', null)
                ->where('expires_at >', date('Y-m-d H:i:s'))
                ->get()
                ->getRowArray();

            if (!$session) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'message' => 'Session is invalid or expired. Please sign in again.',
                ];
            }

            $user = $this->db->table('users')
                ->where('id', $session['user_id'])
                ->get()
                ->getRowArray();

            if (!$user) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'message' => 'The account associated with this session was not found.',
                ];
            }

            if (
                isset($user['account_status']) &&
                strtolower((string) $user['account_status']) !== 'active'
            ) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'message' => 'This account is not active.',
                ];
            }

            $this->db->table('user_sessions')
                ->where('id', $session['id'])
                ->update([
                    'last_used_at' => date('Y-m-d H:i:s'),
                ]);

            return [
                'ok' => true,
                'user' => $user,
            ];
        } catch (Throwable $e) {
            log_message(
                'error',
                'Monthly inventory authentication failed: ' . $e->getMessage()
            );

            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Unable to verify the session.',
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */
    private function canReadReports(array $user): bool
    {
        return $this->isAdmin($user)
            || $this->isFinanceUser($user);
    }

    private function canWriteReports(array $user): bool
    {
        return $this->isFinanceUser($user);
    }

    private function isAdmin(array $user): bool
    {
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        return in_array(
            $role,
            ['admin', 'administrator', 'super_admin', 'super admin'],
            true
        );
    }

    private function isFinanceUser(array $user): bool
    {
        $role = strtolower(trim((string) ($user['role'] ?? '')));

        $department = strtolower(
            trim((string) ($user['department'] ?? ''))
        );

        $financeRoles = [
            'finance',
            'finance_manager',
            'finance manager',
            'finance officer',
            'finance_officer',
        ];

        return in_array($role, $financeRoles, true)
            || $department === 'finance';
    }

    /*
    |--------------------------------------------------------------------------
    | Report serialization
    |--------------------------------------------------------------------------
    */
    private function serializeReport(array $row): array
    {
        $reportMonth = !empty($row['report_month'])
            ? substr((string) $row['report_month'], 0, 10)
            : null;

        $period = !empty($row['period'])
            ? (string) $row['period']
            : null;

        if (!$period && $reportMonth !== null) {
            $period = substr($reportMonth, 0, 7);
        }

        $ethiopianStartDate = (
            $period !== null &&
            $this->isValidPeriod($period)
        )
            ? $this->ethiopianPeriodStartDate($period)
            : null;

        $periodType = (
            $ethiopianStartDate !== null &&
            $reportMonth === $ethiopianStartDate
        )
            ? 'ETHIOPIAN'
            : 'LEGACY_GREGORIAN';

        $lines = $this->getReportLines((string) $row['id']);

        $hasDynamicLines = false;

        foreach ($lines as $line) {
            if ($line['aggregateTypeId'] !== null) {
                $hasDynamicLines = true;
                break;
            }
        }

        /*
         * For new dynamic reports, quantities is a list of aggregate lines.
         * For old reports, preserve the old 00/01/02 object.
         */
        $quantities = $hasDynamicLines
            ? array_values(array_filter(
                $lines,
                fn(array $line): bool => $line['aggregateTypeId'] !== null
            ))
            : [
                '00' => (float) ($row['aggregate_00'] ?? 0),
                '01' => (float) ($row['aggregate_01'] ?? 0),
                '02' => (float) ($row['aggregate_02'] ?? 0),
            ];

        return [
            'id' => $row['id'] ?? null,
            'period' => $period,
            'report_month' => $row['report_month'] ?? null,
            'period_type' => $periodType,

            'status' => strtoupper(
                (string) ($row['status'] ?? 'DRAFT')
            ),

            'quantities' => $quantities,
            'aggregate_lines' => $lines,

            // Legacy fields retained for old frontend consumers.
            'aggregate_00' => (float) ($row['aggregate_00'] ?? 0),
            'aggregate_01' => (float) ($row['aggregate_01'] ?? 0),
            'aggregate_02' => (float) ($row['aggregate_02'] ?? 0),

            'remarks' => $row['remarks'] ?? null,

            'submitted_by' => $row['submitted_by'] ?? null,
            'submitted_by_name' => $row['submitted_by_name'] ?? null,
            'submitted_at' => $row['submitted_at'] ?? null,

            'revision_number' => (int) ($row['revision_number'] ?? 0),
            'version' => (int) ($row['version'] ?? 1),

            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Standard error response
    |--------------------------------------------------------------------------
    */
    private function respondError(
        string $message,
        int $status
    ): ResponseInterface {
        return $this->respond([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
