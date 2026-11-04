<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly string $errorCode = 'BAD_REQUEST',
        public readonly array $errors = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function validation(array $errors, string $message = 'Existem campos inválidos no envio.'): self
    {
        return new self($message, 422, 'VALIDATION_ERROR', $errors);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function conflict(string $message, string $code = 'CONFLICT', array $errors = []): self
    {
        return new self($message, 409, $code, $errors);
    }

    public static function unauthorized(string $message = 'Não autorizado.', string $code = 'UNAUTHORIZED'): self
    {
        return new self($message, 401, $code);
    }

    public static function notFound(string $message = 'Registro não encontrado.'): self
    {
        return new self($message, 404, 'NOT_FOUND');
    }
}

