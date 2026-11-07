<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

class WijayaPayProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation = 'unknown',
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerMessage = null,
        public readonly bool $outcomeUnknown = false,
        public readonly bool $retryable = false,
        public readonly string $reason = 'provider_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function isProviderRejection(): bool
    {
        return $this->reason === 'application_rejection';
    }

    public function isNotFound(): bool
    {
        $message = strtolower($this->providerMessage ?? '');
        if ($this->reason !== 'application_rejection'
            || $message === ''
            || str_contains($message, 'merchant')
        ) {
            return false;
        }

        return str_contains($message, 'transaksi tidak ditemukan')
            || str_contains($message, 'ref id tidak ditemukan')
            || str_contains($message, 'reference not found')
            || str_contains($message, 'transaction not found')
            || str_contains($message, 'not found');
    }
}
