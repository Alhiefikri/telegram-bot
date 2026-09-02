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
        ?Throwable $previous = null,
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
}
