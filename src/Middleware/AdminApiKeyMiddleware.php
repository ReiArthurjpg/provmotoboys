<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $expectedApiKey
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $provided = trim($request->getHeaderLine('X-API-Key'));

        if ($provided === '' || $this->expectedApiKey === '' || !hash_equals($this->expectedApiKey, $provided)) {
            throw ApiException::unauthorized('Chave de administração (X-API-Key) ausente ou inválida.', 'INVALID_ADMIN_KEY');
        }

        return $handler->handle($request);
    }
}

