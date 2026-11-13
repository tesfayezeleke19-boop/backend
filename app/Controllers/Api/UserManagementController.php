<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\RequestInterface;

class UserManagementController extends BaseController
{
    private const ROLES = ['ADMIN', 'SALES', 'FINANCE', 'HR'];

    private function db()
    {
        return db_connect();
    }

    private function actor(): ?array
    {
        $authorization = trim(
            (string) $this->request->getHeaderLine('Authorization')
        );

        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return null;
        }

        $tokenHash = hash('sha256', $matches[1]);

        return $this->db()->table('user_sessions s')
            ->select('u.id, u.email, u.role, u.account_status')
            ->join('users u', 'u.id = s.user_id')
            ->where('s.token_hash', $tokenHash)
            ->where('s.revoked_at', null)
            ->where('s.expires_at >', date('Y-m-d H:i:s'))
            ->where('LOWER(u.account_status)', 'active')
            ->get()
            ->getRowArray();
    }

    private function adminRequired(): ?ResponseInterface
    {
        $actor = $this->actor();

        if (!$actor) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Authentication required.',
                ]);
        }

        if (strtoupper((string) $actor['role']) !== 'ADMIN') {
            return $this->response
                ->setStatusCode(403)
                ->setJSON([
                    'success' => false,
                    'message' => 'Administrator permission required.',
                ]);
        }

        return null;
    }

    private function audit(
        array $actor,
        string $action,
        string $outcome,
        ?string $targetId = null,
        array $details = []
    ): void {
        $this->db()->table('audit_logs')->insert([
            'actor_user_id' => $actor['id'],
            'actor_email' => $actor['email'],
            'action' => $action,
            'target_type' => 'USER',
            'target_id' => $targetId,
            'outcome' => $outcome,
            'details' => json_encode($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function account(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'name' => (string) $user['display_name'],
            'email' => (string) $user['email'],
            'role' => strtoupper((string) $user['role']),
            'department' => (string) ($user['department'] ?? ''),
            'accountStatus' => strtoupper((string) $user['account_status']),
            'createdAt' => strtotime($user['created_at']) * 1000,
            'updatedAt' => strtotime($user['updated_at']) * 1000,
        ];
    }

    public function index(): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $users = $this->db()->table('users')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'data' => array_map(
                fn ($user) => $this->account($user),
                $users
            ),
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $actor = $this->actor();
        $data = $this->request->getJSON(true);

        if (!is_array($data)) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Invalid JSON request body.',
            ]);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = strtoupper(trim((string) ($data['role'] ?? '')));
        $department = trim((string) ($data['department'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'A name and valid email address are required.',
            ]);
        }

        if (!in_array($role, self::ROLES, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Unsupported user role.',
            ]);
        }

        $db = $this->db();

        $existing = $db->table('users')
            ->where('email', $email)
            ->get()
            ->getRowArray();

        if ($existing) {
            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'message' => 'A user with this email already exists.',
            ]);
        }

        // The UI must supply an initial password until an invitation/reset
        // flow is implemented. Never store plaintext passwords.
        $initialPassword = (string) ($data['initialPassword'] ?? '');

        if (strlen($initialPassword) < 12) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'An initial password of at least 12 characters is required.',
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->uuid();

        $db->transBegin();

        $db->table('users')->insert([
            'id' => $id,
            'display_name' => $name,
            'email' => $email,
            'password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT),
            'role' => $role,
            'department' => $department !== '' ? $department : $this->departmentForRole($role),
            'account_status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Could not create the user account.',
            ]);
        }

        $this->audit($actor, 'USER_CREATED', 'SUCCESS', $id, [
            'email' => $email,
            'role' => $role,
        ]);

        $db->transComplete();

        $user = $db->table('users')->where('id', $id)->get()->getRowArray();

        return $this->response->setStatusCode(201)->setJSON([
            'success' => true,
            'data' => $this->account($user),
        ]);
    }

    public function update(string $id): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $actor = $this->actor();
        $data = $this->request->getJSON(true);

        if (!is_array($data)) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Invalid JSON request body.',
            ]);
        }

        $db = $this->db();
        $existing = $db->table('users')->where('id', $id)->get()->getRowArray();

        if (!$existing) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'User account not found.',
            ]);
        }

        $name = trim((string) ($data['name'] ?? $existing['display_name']));
        $email = strtolower(trim((string) ($data['email'] ?? $existing['email'])));
        $role = strtoupper(trim((string) ($data['role'] ?? $existing['role'])));
        $department = trim((string) ($data['department'] ?? $existing['department'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'A name and valid email address are required.',
            ]);
        }

        if (!in_array($role, self::ROLES, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Unsupported user role.',
            ]);
        }

        $duplicate = $db->table('users')
            ->where('LOWER(email)', $email)
            ->where('id !=', $id)
            ->countAllResults();

        if ($duplicate > 0) {
            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'message' => 'Another account already uses this email.',
            ]);
        }

        if (
            strtoupper((string) $existing['role']) === 'ADMIN'
            && $role !== 'ADMIN'
            && strtoupper((string) $existing['account_status']) === 'ACTIVE'
        ) {
            $otherAdmins = $db->table('users')
                ->where('role', 'ADMIN')
                ->where('account_status', 'ACTIVE')
                ->where('id !=', $id)
                ->countAllResults();

            if ($otherAdmins === 0) {
                return $this->response->setStatusCode(409)->setJSON([
                    'success' => false,
                    'message' => 'Cannot demote the last active administrator.',
                ]);
            }
        }

        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        $db->table('users')->where('id', $id)->update([
            'display_name' => $name,
            'email' => $email,
            'role' => $role,
            'department' => $department !== '' ? $department : $this->departmentForRole($role),
            'updated_at' => $now,
        ]);

        $roleChanged = strtoupper((string) $existing['role']) !== $role;

        $this->audit(
            $actor,
            $roleChanged ? 'ROLE_CHANGED' : 'USER_UPDATED',
            'SUCCESS',
            $id,
            [
                'before' => [
                    'role' => $existing['role'],
                    'department' => $existing['department'],
                ],
                'after' => [
                    'role' => $role,
                    'department' => $department,
                ],
            ]
        );

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Could not update the user account.',
            ]);
        }

        $updated = $db->table('users')->where('id', $id)->get()->getRowArray();

        return $this->response->setJSON([
            'success' => true,
            'data' => $this->account($updated),
        ]);
    }

    public function setStatus(string $id): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $actor = $this->actor();
        $data = $this->request->getJSON(true);
        $status = strtoupper(trim((string) ($data['accountStatus'] ?? '')));

        if (!in_array($status, ['ACTIVE', 'DISABLED'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Status must be ACTIVE or DISABLED.',
            ]);
        }

        $db = $this->db();
        $existing = $db->table('users')->where('id', $id)->get()->getRowArray();

        if (!$existing) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'User account not found.',
            ]);
        }

        if ((string) $actor['id'] === $id) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'You cannot change your own account status.',
            ]);
        }

        if (
            strtoupper((string) $existing['role']) === 'ADMIN'
            && strtoupper((string) $existing['account_status']) === 'ACTIVE'
            && $status === 'DISABLED'
        ) {
            $otherAdmins = $db->table('users')
                ->where('role', 'ADMIN')
                ->where('account_status', 'ACTIVE')
                ->where('id !=', $id)
                ->countAllResults();

            if ($otherAdmins === 0) {
                return $this->response->setStatusCode(409)->setJSON([
                    'success' => false,
                    'message' => 'Cannot disable the last active administrator.',
                ]);
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->transBegin();

        $db->table('users')->where('id', $id)->update([
            'account_status' => $status,
            'updated_at' => $now,
        ]);

        // Revoke all sessions when disabling an account.
        if ($status === 'DISABLED') {
            $db->table('user_sessions')
                ->where('user_id', $id)
                ->where('revoked_at', null)
                ->update(['revoked_at' => $now]);
        }

        $action = $status === 'DISABLED'
            ? 'ACCOUNT_DISABLED'
            : 'ACCOUNT_REACTIVATED';

        $this->audit($actor, $action, 'SUCCESS', $id, [
            'before' => $existing['account_status'],
            'after' => $status,
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Could not change account status.',
            ]);
        }

        $updated = $db->table('users')->where('id', $id)->get()->getRowArray();

        return $this->response->setJSON([
            'success' => true,
            'data' => $this->account($updated),
        ]);
    }

    public function auditLog(): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $rows = $this->db()->table('audit_logs')
            ->where('target_type', 'USER')
            ->orderBy('created_at', 'DESC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $data = array_map(static function ($row) {
            $details = is_array($row['details'])
                ? $row['details']
                : json_decode((string) $row['details'], true);

            return [
                'id' => (string) $row['id'],
                'timestamp' => strtotime($row['created_at']) * 1000,
                'actorId' => (string) ($row['actor_user_id'] ?? ''),
                'actorEmail' => (string) $row['actor_email'],
                'action' => (string) $row['action'],
                'targetUserId' => (string) ($row['target_id'] ?? ''),
                'targetEmail' => (string) ($details['email'] ?? ''),
                'outcome' => (string) $row['outcome'],
                'details' => json_encode($details),
            ];
        }, $rows);

        return $this->response->setJSON([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function departmentForRole(string $role): string
    {
        return match ($role) {
            'ADMIN' => 'Administration',
            'FINANCE' => 'Finance',
            'HR' => 'Human Resources',
            default => 'Sales',
        };
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
}
