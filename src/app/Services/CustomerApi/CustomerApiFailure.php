<?php

namespace App\Services\CustomerApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CustomerApiFailure extends RuntimeException
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $details  Extra allowlisted machine-readable error members.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $machineCode,
        string $message,
        array $headers = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
        $this->headers = $status === 503 ? $headers + ['Retry-After' => '5'] : $headers;
    }

    public static function unavailable(): self
    {
        return new self(503, 'temporarily_unavailable', 'API admission is temporarily unavailable.');
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
            400 => $error instanceof MalformedUrlException ? 'invalid_path' : 'request_failed',
            401 => 'unauthenticated', 403 => 'not_authorized', 404 => 'not_found', 405 => 'method_not_allowed',
            413 => 'payload_too_large', 422 => 'invalid_input', 429 => 'rate_limited',
            503 => 'temporarily_unavailable', default => 'request_failed',
        };
        $headers = [];
        if ($status === 429) {
            $retry = $error instanceof HttpExceptionInterface
                ? ($error->getHeaders()['Retry-After'] ?? 60) : 60;
            $headers['Retry-After'] = (string) max(1, (int) $retry);
        }

        return new self($status, $code, $status === 404 ? 'Resource not found.' : 'The API request could not be completed.', $headers);
    }
}
