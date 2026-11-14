<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Cors implements FilterInterface
{
    private array $allowedOrigins = [

    // Production frontend
    'https://frontend-kappa-nine-44.vercel.app',
];

    private function applyCorsHeaders(
        RequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');

        if (!in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }

        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->setHeader('Vary', 'Origin');

        $response->setHeader(
            'Access-Control-Allow-Methods',
            'GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );

        $response->setHeader(
            'Access-Control-Allow-Headers',
            'Authorization, Content-Type, Accept, Origin, X-Requested-With'
        );

        $response->setHeader('Access-Control-Max-Age', '86400');

        return $response;
    }

    public function before(
        RequestInterface $request,
        $arguments = null
    ) {
        $response = service('response');

        $this->applyCorsHeaders($request, $response);

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $response->setStatusCode(204);
        }

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        return $this->applyCorsHeaders($request, $response);
    }
}
