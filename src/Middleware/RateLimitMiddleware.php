<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Redis;
use Throwable;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?Redis $redis,
        private readonly int $generalLimitPerMin = 300
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->redis === null) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (
            str_ends_with($path, '/health')
            || str_contains($path, '/docs')
            || str_ends_with($path, '/openapi.json')
        ) {
            return $handler->handle($request);
        }

        $ip = $this->resolveClientIp($request);
        $minuteSlot = (int) floor(time() / 60);
        $key = sprintf('rl:motoboys:%s:%d', $ip, $minuteSlot);

        $remaining = $this->generalLimitPerMin;
        try {
            $current = (int) $this->redis->incr($key);
            if ($current === 1) {
                $this->redis->expire($key, 65);
            }
            $remaining = max(0, $this->generalLimitPerMin - $current);
            if ($current > $this->generalLimitPerMin) {
                throw new ApiException(
                    'Muitas requisições em sequência. Aguarde alguns segundos e tente novamente.',
                    429,
                    'TOO_MANY_REQUESTS'
                );
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
        }

        $response = $handler->handle($request);
        return $response->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        $serverParams = $request->getServerParams();
        return (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }
}

