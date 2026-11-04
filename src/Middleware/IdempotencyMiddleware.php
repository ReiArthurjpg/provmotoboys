<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Redis;
use Slim\Psr7\Response;
use Throwable;

final class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?Redis $redis
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->redis === null || strtoupper($request->getMethod()) !== 'POST') {
            return $handler->handle($request);
        }

        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return $handler->handle($request);
        }

        $cacheKey = 'idemp:' . hash('sha256', $request->getUri()->getPath() . ':' . $idempotencyKey);

        try {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['status'], $decoded['body'])) {
                    $response = new Response((int) $decoded['status']);
                    $response->getBody()->write((string) $decoded['body']);
                    return $response
                        ->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withHeader('X-Idempotent-Replay', 'true');
                }
            }
        } catch (Throwable) {
        }

        $response = $handler->handle($request);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            try {
                $body = (string) $response->getBody();
                $this->redis->setex($cacheKey, 600, (string) json_encode([
                    'status' => $status,
                    'body' => $body,
                ]));
            } catch (Throwable) {
            }
        }

        return $response;
    }
}

