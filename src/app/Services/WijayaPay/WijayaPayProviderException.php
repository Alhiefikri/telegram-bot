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

    /**
     * A transaction lookup that the provider explicitly answered as missing.
     *
     * This is intentionally narrower than "any 404": only the documented
     * status lookup may prove that no transaction exists for a merchant
     * reference. HTTP failures, malformed responses, merchant/credential
     * errors and callbacks remain provider outages or invalid evidence and
     * must never authorize a second create.
     */
    public function isNotFound(): bool
    {
        if ($this->operation !== 'transactionStatus' || $this->reason !== 'application_rejection') {
            return false;
        }

        $message = strtolower($this->providerMessage ?? '');
        if ($message === ''
            || str_contains($message, 'merchant')
            || str_contains($message, 'api key')
            || str_contains($message, 'kredensial')
            || str_contains($message, 'credential')
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
