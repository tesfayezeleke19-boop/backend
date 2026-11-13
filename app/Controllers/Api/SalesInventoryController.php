<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class SalesInventoryController extends ResourceController
{
    protected $format = 'json';

    private ?array $authenticatedUser = null;

    private function db(): BaseConnection
    {
        return \Config\Database::connect();
    }

    /**
     * Validate the bearer token and load the active user.
     */
    private function authenticate(): ?ResponseInterface
    {
        $db = $this->db();

        $header = trim(
            $this->request->getHeaderLine('Authorization')
        );

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->fail(
                'A valid bearer token is required.',
                401
            );
        }

        $token = trim($matches[1]);

        if ($token === '') {
            return $this->fail(
                'A valid bearer token is required.',
                401
            );
        }

        $tokenHash = hash('sha256', $token);
        $now = date('Y-m-d H:i:s');

        $session = $db->table('user_sessions')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->where('expires_at >', $now)
            ->get()
            ->getRowArray();

        if (!$session) {
            return $this->fail(
                'Your session is invalid or expired.',
                401
            );
        }

        $user = $db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (
            !$user ||
            strtoupper(trim(
                (string) ($user['account_status'] ?? '')
            )) !== 'ACTIVE'
        ) {
            return $this->fail(
                'This account is unavailable.',
                401
            );
        }

        // Update session activity.
        try {
            $db->table('user_sessions')
                ->where('id', $session['id'])
                ->update([
                    'last_used_at' => $now,
                ]);
        } catch (Throwable $e) {
            log_message(
                'warning',
                'Could not update Sales inventory session activity: '
                . $e->getMessage()
            );
        }

        $this->authenticatedUser = $user;

        return null;
    }

    /**
     * Restrict this endpoint to Sales and Admin users.
     */
    private function authorize(): ?ResponseInterface
    {
        $authError = $this->authenticate();

        if ($authError !== null) {
            return $authError;
        }

        $role = strtoupper(
            trim(
                (string) ($this->authenticatedUser['role'] ?? '')
            )
        );

        if (!in_array($role, ['SALES', 'ADMIN'], true)) {
            return $this->fail(
                'You are not authorized to view Sales inventory reports.',
                403
            );
        }

        return null;
    }

    /**
     * Convert a database inventory line into the API format.
     *
     * aggregate_name is a saved name snapshot. We deliberately
     * do not retrieve the current aggregate type name because
     * historical reports must retain their original names.
     */
    private function serializeInventoryLine(array $line): array
    {
        $aggregateTypeId = $line['aggregate_type_id'] ?? null;

        $aggregateName = trim(
            (string) ($line['aggregate_name'] ?? '')
        );

        // Fallback for older inventory lines.
        if ($aggregateName === '') {
            $aggregateName = trim(
                (string) ($line['aggregate'] ?? '')
            );
        }

        if ($aggregateName === '') {
            $aggregateName = $aggregateTypeId !== null
                ? 'Aggregate ' . $aggregateTypeId
                : 'Historical Aggregate';
        }

        return [
            'id' => $line['id'] ?? null,

            'aggregateTypeId' => $aggregateTypeId !== null
                ? (string) $aggregateTypeId
                : null,

            'aggregateName' => $aggregateName,

            'quantity' => (float) (
                $line['unsold_quantity'] ?? 0
            ),

            'isLegacy' => $aggregateTypeId === null,
        ];
    }

    /**
     * GET /api/sales/inventory/monthly-reports
     *
     * Return Finance-published monthly inventory reports.
     *
     * Dynamic aggregate quantities are loaded from:
     * monthly_inventory_report_lines
     *
     * Draft reports are never returned.
     */
    public function index(): ResponseInterface
    {
        $authorizationError = $this->authorize();

        if ($authorizationError !== null) {
            return $authorizationError;
        }

        try {
            $db = $this->db();

            // -------------------------------------------------
            // 1. LOAD PUBLISHED REPORTS
            // -------------------------------------------------

            $reports = $db->table('monthly_inventory_reports')
                ->select([
                    'id',
                    'report_month',
                    'period',
                    'status',
                    'aggregate_00',
                    'aggregate_01',
                    'aggregate_02',
                    'remarks',
                    'submitted_by_name',
                    'submitted_at',
                    'created_at',
                    'updated_at',
                    'version',
                    'revision_number',
                ])
                ->whereIn(
                    'status',
                    ['SUBMITTED', 'CORRECTED']
                )
                ->orderBy('report_month', 'DESC')
                ->get()
                ->getResultArray();

            if (empty($reports)) {
                return $this->respond([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // -------------------------------------------------
            // 2. COLLECT REPORT IDS
            // -------------------------------------------------

            $reportIds = array_column($reports, 'id');

            // -------------------------------------------------
            // 3. LOAD ALL DYNAMIC AGGREGATE LINES
            // -------------------------------------------------

            $inventoryLines = $db->table(
                'monthly_inventory_report_lines'
            )
                ->select([
                    'id',
                    'report_id',
                    'aggregate_type_id',
                    'aggregate_name',
                    'aggregate',
                    'unsold_quantity',
                ])
                ->whereIn('report_id', $reportIds)
                ->orderBy('aggregate_type_id', 'ASC')
                ->orderBy('aggregate_name', 'ASC')
                ->get()
                ->getResultArray();

            // -------------------------------------------------
            // 4. GROUP LINES BY REPORT ID
            // -------------------------------------------------

            $linesByReport = [];

            foreach ($inventoryLines as $line) {
                $reportId = (string) $line['report_id'];

                if (!isset($linesByReport[$reportId])) {
                    $linesByReport[$reportId] = [];
                }

                $linesByReport[$reportId][] =
                    $this->serializeInventoryLine($line);
            }

            // -------------------------------------------------
            // 5. BUILD API RESPONSE
            // -------------------------------------------------

            $data = [];

            foreach ($reports as $report) {
                $reportId = (string) $report['id'];

                $reportMonth = $report['report_month'];

                $period = !empty($report['period'])
                    ? $report['period']
                    : date(
                        'Y-m',
                        strtotime($reportMonth)
                    );

                $dynamicLines = $linesByReport[$reportId] ?? [];

                /*
                 * New dynamic reports:
                 * Return an array of aggregate lines.
                 *
                 * Old reports without dynamic lines:
                 * Return the original fixed quantity object
                 * for backward compatibility.
                 */
                if (!empty($dynamicLines)) {
                    $quantities = $dynamicLines;
                } else {
                    $quantities = [
                        '00' => (float) (
                            $report['aggregate_00'] ?? 0
                        ),
                        '01' => (float) (
                            $report['aggregate_01'] ?? 0
                        ),
                        '02' => (float) (
                            $report['aggregate_02'] ?? 0
                        ),
                    ];
                }

                $data[] = [
                    'id' => $reportId,

                    'period' => $period,

                    'status' => $report['status'],

                    // Dynamic aggregate lines for the frontend.
                    'quantities' => $quantities,

                    // Explicit line collection for compatibility.
                    'aggregate_lines' => $dynamicLines,

                    'remarks' => $report['remarks'],

                    'submittedByName' =>
                        $report['submitted_by_name'],

                    'submittedAt' =>
                        $report['submitted_at'],

                    'createdAt' =>
                        $report['created_at'],

                    'updatedAt' =>
                        $report['updated_at'],

                    'version' => (int) (
                        $report['version'] ?? 1
                    ),

                    'revisionNumber' => (int) (
                        $report['revision_number'] ?? 0
                    ),
                ];
            }

            // -------------------------------------------------
            // 6. RETURN RESPONSE
            // -------------------------------------------------

            return $this->respond([
                'success' => true,
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            log_message(
                'error',
                'Sales inventory reports error: '
                . $e->getMessage()
            );

            return $this->fail(
                'Unable to load monthly inventory reports.',
                500
            );
        }
    }
}
