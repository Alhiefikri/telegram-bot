<?php

namespace App\Services\CustomerApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CustomerApiFailure extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $machineCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(503, 'temporarily_unavailable', 'API admission is temporarily unavailable.', ['Retry-After' => '5']);
    }

    public static function fromException(\Throwable $error): self
    {
        if ($error instanceof self) {
            return $error;
        }
        $status = match (true) {
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 503,
        };
        $code = match ($status) {
            401 => 'unauthenticated', 403 => 'not_authorized', 404 => 'not_found', 405 => 'method_not_allowed',
            413 => 'payload_too_large', 422 => 'invalid_input', 429 => 'rate_limited',
            503 => 'temporarily_unavailable', default => 'request_failed',
        };
        $headers = $status === 503 ? ['Retry-After' => '5'] : [];
        if ($status === 429) {
            $retry = $error instanceof HttpExceptionInterface
                ? ($error->getHeaders()['Retry-After'] ?? 60) : 60;
            $headers['Retry-After'] = (string) max(1, (int) $retry);
        }

        return new self($status, $code, $status === 404 ? 'Resource not found.' : 'The API request could not be completed.', $headers);
    }
}
