<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class LeaveController extends BaseController
{
    private const LEAVE_TYPES = [
        'Annual',
        'Sick',
        'Personal',
        'Maternity',
        'Paternity',
        'Unpaid',
    ];

    /**
     * GET /api/leave-requests
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $builder = $db->table('leave_requests lr');

        $builder
            ->select([
                'lr.id',
                'lr.leave_id',
                'e.employee_id AS employee_id',
                'lr.leave_type AS type',
                'lr.start_date',
                'lr.end_date',
                'lr.reason',
                'lr.status',
                'lr.created_at AS created_date',
                'reviewer.display_name AS reviewed_by',
                'lr.reviewed_at AS reviewed_date',
                'lr.rejection_reason',
            ])
            ->join('employees e', 'e.id = lr.employee_id')
            ->join('users reviewer', 'reviewer.id = lr.reviewed_by', 'left')
            ->orderBy('lr.created_at', 'DESC');

        $status = strtoupper(trim((string) $this->request->getGet('status')));

        if ($status !== '') {
            if (!in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'message' => 'Invalid leave status.',
                ]);
            }

            $builder->where('lr.status', $status);
        }

        return $this->response->setJSON([
            'data' => $builder->get()->getResultArray(),
        ]);
    }

    /**
     * GET /api/leave-requests/{id}
     */
    public function show(string $id): ResponseInterface
    {
        $request = $this->findRequest($id);

        if (!$request) {
            return $this->response->setStatusCode(404)->setJSON([
                'message' => 'Leave request not found.',
            ]);
        }

        return $this->response->setJSON([
            'data' => $request,
        ]);
    }

    /**
     * POST /api/leave-requests
     *
     * Expected JSON:
     * {
     *   "employee_id": "EMP-001",
     *   "type": "Annual",
     *   "start_date": "2026-11-01",
     *   "end_date": "2026-11-05",
     *   "reason": "Personal travel"
     * }
     */
    public function create(): ResponseInterface
    {
        $input = $this->request->getJSON(true);

        if (!is_array($input)) {
            return $this->response->setStatusCode(400)->setJSON([
                'message' => 'A valid JSON request body is required.',
            ]);
        }

        $employeeCode = trim((string) ($input['employee_id'] ?? ''));
        $type = trim((string) ($input['type'] ?? ''));
        $startDate = trim((string) ($input['start_date'] ?? ''));
        $endDate = trim((string) ($input['end_date'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if (
            $employeeCode === '' ||
            $type === '' ||
            $startDate === '' ||
            $endDate === '' ||
            $reason === ''
        ) {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'Employee, leave type, dates, and reason are required.',
            ]);
        }

        if (!in_array($type, self::LEAVE_TYPES, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'Invalid leave type.',
            ]);
        }

        if (
            !$this->isValidDate($startDate) ||
            !$this->isValidDate($endDate) ||
            $endDate < $startDate
        ) {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'Please provide valid dates. The end date must not precede the start date.',
            ]);
        }

        $db = db_connect();

        $employee = $db->table('employees')
            ->select('id, employee_id')
            ->where('employee_id', $employeeCode)
            ->get()
            ->getRowArray();

        if (!$employee) {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'The selected employee does not exist.',
            ]);
        }

        // Generate a unique, readable request number.
        $leaveId = 'LR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));

        $db->table('leave_requests')->insert([
            'leave_id' => $leaveId,
            'employee_id' => $employee['id'],
            'leave_type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
            'status' => 'PENDING',
        ]);

        if ($db->affectedRows() !== 1) {
            return $this->response->setStatusCode(500)->setJSON([
                'message' => 'Unable to create the leave request.',
            ]);
        }

        $created = $this->findRequest($leaveId);

        return $this->response
            ->setStatusCode(201)
            ->setJSON(['data' => $created]);
    }

    /**
     * POST /api/leave-requests/{id}/approve
     */
    public function approve(string $id): ResponseInterface
    {
        return $this->review($id, 'APPROVED', null);
    }

    /**
     * POST /api/leave-requests/{id}/reject
     *
     * Expected JSON: { "reason": "Insufficient staffing" }
     */
    public function reject(string $id): ResponseInterface
    {
        $input = $this->request->getJSON(true);
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'A rejection reason is required.',
            ]);
        }

        return $this->review($id, 'REJECTED', $reason);
    }

    private function review(
        string $id,
        string $status,
        ?string $rejectionReason
    ): ResponseInterface {
        $db = db_connect();
        $request = $this->findRequest($id);

        if (!$request) {
            return $this->response->setStatusCode(404)->setJSON([
                'message' => 'Leave request not found.',
            ]);
        }

        if ($request['status'] !== 'PENDING') {
            return $this->response->setStatusCode(409)->setJSON([
                'message' => 'Only pending leave requests can be reviewed.',
            ]);
        }

        /*
         * The current frontend passes the reviewer name, but the database
         * stores a UUID. Until authentication middleware exposes the
         * authenticated user's UUID, leave reviewed_by NULL rather than
         * incorrectly storing a display name in a UUID column.
         *
         * We will connect the authenticated user in a later security step.
         */
        $db->table('leave_requests')
            ->where('id', $request['id'])
            ->where('status', 'PENDING')
            ->update([
                'status' => $status,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $rejectionReason,
            ]);

        if ($db->affectedRows() !== 1) {
            return $this->response->setStatusCode(409)->setJSON([
                'message' => 'The request may already have been reviewed. Refresh and try again.',
            ]);
        }

        return $this->response->setJSON([
            'data' => $this->findRequest($id),
        ]);
    }

    private function findRequest(string $id): ?array
    {
        $builder = db_connect()->table('leave_requests lr');

        $result = $builder
            ->select([
                'lr.id',
                'lr.leave_id',
                'e.employee_id AS employee_id',
                'lr.leave_type AS type',
                'lr.start_date',
                'lr.end_date',
                'lr.reason',
                'lr.status',
                'lr.created_at AS created_date',
                'reviewer.display_name AS reviewed_by',
                'lr.reviewed_at AS reviewed_date',
                'lr.rejection_reason',
            ])
            ->join('employees e', 'e.id = lr.employee_id')
            ->join('users reviewer', 'reviewer.id = lr.reviewed_by', 'left')
            ->groupStart()
                ->where('lr.leave_id', $id)
                ->orWhere('lr.id', $id)
            ->groupEnd()
            ->get()
            ->getRowArray();

        return $result ?: null;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    }
}
