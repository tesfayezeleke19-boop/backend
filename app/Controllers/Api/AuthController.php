<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseController
{
    public function login(): ResponseInterface
    {
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
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Email and password are required.',
                ]);
        }

        $db = db_connect();

        $user = $db->table('users')
            ->where('email', $email)
            ->get()
            ->getRowArray();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Invalid email or password.',
                ]);
        }

        if (strtolower((string) $user['account_status']) !== 'active') {
            return $this->response
                ->setStatusCode(403)
                ->setJSON([
                    'success' => false,
                    'message' => 'This account is not active.',
                ]);
        }

        // Generate an opaque token. Store only its SHA-256 hash.
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Generate a UUID v4 for the session record.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $sessionId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + 8 * 60 * 60);

        $db->transStart();

        $db->table('user_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'last_used_at' => $now,
            'user_agent' => substr((string) $this->request->getUserAgent(), 0, 1000),
            'ip_address' => $this->request->getIPAddress(),
        ]);

        $db->table('users')
            ->where('id', $user['id'])
            ->update([
                'last_signed_in_at' => $now,
                'updated_at' => $now,
            ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to complete sign-in.',
                ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Sign-in successful.',
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => $user['id'],
                'display_name' => $user['display_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'department' => $user['department'],
            ],
        ]);
    }
}
