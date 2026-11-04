<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ApiException;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;
use Throwable;

final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $debug = false
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            $payload = [
                'error' => true,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
            if ($e->errors !== []) {
                $payload['errors'] = $e->errors;
            }
            return JsonResponse::send(new Response(), $payload, $e->httpStatus);
        } catch (HttpException $e) {
            return JsonResponse::send(new Response(), [
                'error' => true,
                'code' => 'HTTP_' . $e->getCode(),
                'message' => $e->getMessage(),
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404);
        } catch (Throwable $e) {
            error_log(sprintf('[MOTOBOYS_API_ERROR] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $payload = [
                'error' => true,
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Ocorreu um erro interno ao processar sua solicitação.',
            ];
            if ($this->debug) {
                $payload['debug'] = $e->getMessage();
            }
            return JsonResponse::send(new Response(), $payload, 500);
        }
    }
}

