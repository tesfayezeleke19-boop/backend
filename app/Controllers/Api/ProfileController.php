<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ProfileController extends BaseController
{
    /**
     * Resolve the current user from the Bearer token.
     * The database stores SHA-256 token hashes, not raw tokens.
     */
    private function currentSession(): ?array
    {
        $authorization = trim(
            $this->request->getHeaderLine('Authorization')
        );

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        if ($token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $db = db_connect();

        $session = $db->table('user_sessions')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:sP'))
            ->get()
            ->getRowArray();

        if (!$session) {
            return null;
        }

        $user = $db->table('users')
            ->where('id', $session['user_id'])
            ->get()
            ->getRowArray();

        if (
            !$user ||
            strtolower((string) $user['account_status']) !== 'active'
        ) {
            return null;
        }

        return [
            'session' => $session,
            'user' => $user,
        ];
    }

    /**
     * GET /api/profile
     */
    public function show(): ResponseInterface
    {
        $current = $this->currentSession();

        if (!$current) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your session is invalid or has expired. Please sign in again.',
                ]);
        }

        $user = $current['user'];

        return $this->response->setJSON([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'display_name' => $user['display_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'department' => $user['department'],
                'account_status' => $user['account_status'],
                'created_at' => $user['created_at'],
                'last_signed_in_at' => $user['last_signed_in_at'],
            ],
        ]);
    }

    /**
     * PUT /api/profile/email
     */
    public function updateEmail(): ResponseInterface
    {
        $current = $this->currentSession();

        if (!$current) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your session is invalid or has expired. Please sign in again.',
                ]);
        }

        $data = $this->request->getJSON(true);

        if (!is_array($data)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Invalid request body.',
                ]);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Please enter a valid email address.',
                ]);
        }

        $user = $current['user'];
        $db = db_connect();

        if (strtolower($user['email']) === $email) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'This is already your email address.',
                'email' => $user['email'],
            ]);
        }

        // Check whether another account already uses this email.
        $existing = $db->query(
            'SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1',
            [$email, $user['id']]
        )->getRowArray();

        if ($existing) {
            return $this->response
                ->setStatusCode(409)
                ->setJSON([
                    'success' => false,
                    'message' => 'This email address is already registered.',
                ]);
        }

        $updated = $db->table('users')
            ->where('id', $user['id'])
            ->update([
                'email' => $email,
                'updated_at' => date('Y-m-d H:i:sP'),
            ]);

        if (!$updated || $db->affectedRows() < 1) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to update your email address.',
                ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Your email address has been updated successfully.',
            'email' => $email,
        ]);
    }

    /**
     * PUT /api/profile/password
     */
    public function updatePassword(): ResponseInterface
    {
        $current = $this->currentSession();

        if (!$current) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your session is invalid or has expired. Please sign in again.',
                ]);
        }

        $data = $this->request->getJSON(true);

        if (!is_array($data)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Invalid request body.',
                ]);
        }

        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'All password fields are required.',
                ]);
        }

        $user = $current['user'];

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your current password is incorrect.',
                ]);
        }

        if (strlen($newPassword) < 12) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your new password must contain at least 12 characters.',
                ]);
        }

        if ($newPassword !== $confirmPassword) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'The new password and confirmation do not match.',
                ]);
        }

        if (password_verify($newPassword, $user['password_hash'])) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your new password must be different from your current password.',
                ]);
        }

        $db = db_connect();
        $now = date('Y-m-d H:i:sP');
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $db->transStart();

        $db->table('users')
            ->where('id', $user['id'])
            ->update([
                'password_hash' => $newHash,
                'password_changed_at' => $now,
                'updated_at' => $now,
            ]);

        // Revoke other sessions, but keep the current session active.
        $db->table('user_sessions')
            ->where('user_id', $user['id'])
            ->where('id !=', $current['session']['id'])
            ->where('revoked_at', null)
            ->update([
                'revoked_at' => $now,
            ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to update your password.',
                ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Your password has been changed successfully.',
        ]);
    }
}
