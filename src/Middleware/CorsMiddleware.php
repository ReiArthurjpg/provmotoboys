<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var array<int, string> */
    private array $allowedOrigins;

    public function __construct(string $originsCsv = '*')
    {
        $parts = array_filter(array_map('trim', explode(',', $originsCsv)));
        $this->allowedOrigins = $parts !== [] ? $parts : ['*'];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = '*';

        if (!in_array('*', $this->allowedOrigins, true)) {
            if ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
                $allowOrigin = $origin;
            } else {
                $allowOrigin = $this->allowedOrigins[0];
            }
        } elseif ($origin !== '') {
            $allowOrigin = $origin;
        }

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = new Response(204);
            return $this->applyHeaders($response, $allowOrigin);
        }

        $response = $handler->handle($request);
        return $this->applyHeaders($response, $allowOrigin);
    }

    private function applyHeaders(ResponseInterface $response, string $allowOrigin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Content-Type, Authorization, Accept, X-Registration-Token, Idempotency-Key, X-API-Key'
            )
            ->withHeader('Access-Control-Expose-Headers', 'X-RateLimit-Remaining, X-Idempotent-Replay')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}

