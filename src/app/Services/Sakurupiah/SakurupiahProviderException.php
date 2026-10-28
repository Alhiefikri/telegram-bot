<?php

namespace App\Services\Sakurupiah;

use RuntimeException;
use Throwable;

class SakurupiahProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUnknown = false,
        public readonly ?string $operation = null,
        /**
         * Application status reported by the provider's documented failure
         * contract. Null means the provider never answered with that contract,
         * so the request outcome is not established by this exception.
         */
        public readonly ?string $providerStatus = null,
        /**
         * Sanitized provider failure reason. Never carries a credential or an
         * identifier; absent when the provider contract has no message.
         */
        public readonly ?string $providerMessage = null,
        ?Throwable $previous = null,
        public readonly ?int $retryAt = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }

    public function operationName(): ?string
    {
        return $this->operation;
    }

    /**
     * The provider answered with its own failure contract (status plus a
     * documented reason) instead of an ambiguous transport or proxy failure,
     * and that status is a client-error decision.
     *
     * A provider 5xx-class application status may have partially created the
     * transaction, so it stays ambiguous rather than becoming a decision. For a
     * create, a rejection proves that no transaction exists for the merchant
     * reference yet.
     */
    public function isProviderRejection(): bool
    {
        return $this->providerStatus !== null
            && preg_match('/\A4\d{2}\z/', $this->providerStatus) === 1;
    }
}
