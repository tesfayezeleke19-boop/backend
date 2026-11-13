<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthToken implements FilterInterface
{
    public function before(
        RequestInterface $request,
        $arguments = null
    ) {
        $response = service('response');

        $authorization = trim(
            (string) $request->getHeaderLine('Authorization')
        );

        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return $response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Authentication token required.',
                ]);
        }

        $tokenHash = hash('sha256', $matches[1]);

        $db = db_connect();

        $session = $db->table('user_sessions s')
            ->select([
                's.id AS session_id',
                's.user_id',
                's.expires_at',
                'u.email',
                'u.role',
                'u.account_status',
            ])
            ->join('users u', 'u.id = s.user_id')
            ->where('s.token_hash', $tokenHash)
            ->where('s.revoked_at', null)
            ->where('s.expires_at >', date('Y-m-d H:i:s'))
            ->where('LOWER(u.account_status)', 'active')
            ->get()
            ->getRowArray();

        if (!$session) {
            return $response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Your session is invalid or expired. Please sign in again.',
                ]);
        }

        // Update activity for every authenticated user.
        // Role-based permissions must be checked by the individual
        // protected routes/controllers, not by this authentication filter.
        $db->table('user_sessions')
            ->where('id', $session['session_id'])
            ->update([
                'last_used_at' => date('Y-m-d H:i:s'),
            ]);

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        // No post-response processing required.
    }
}
