<?php

namespace App\Services\Telegram;

final readonly class StoreBalanceTopUpOutboundResult
{
    public function __construct(
        public StoreBalanceTopUpOutboundOutcome $outcome,
        public bool $retryable = false,
    ) {}

    public function isDelivered(): bool
    {
        return $this->outcome === StoreBalanceTopUpOutboundOutcome::Delivered;
    }

    public function isQueued(): bool
    {
        return $this->outcome === StoreBalanceTopUpOutboundOutcome::Queued;
    }

    public function isAccepted(): bool
    {
        return in_array($this->outcome, [
            StoreBalanceTopUpOutboundOutcome::Queued,
            StoreBalanceTopUpOutboundOutcome::Delivered,
        ], true);
    }

    public function requiresReview(): bool
    {
        return $this->outcome === StoreBalanceTopUpOutboundOutcome::ReviewRequired;
    }

    public function canRetry(): bool
    {
        return $this->retryable;
    }

    /** @return array{outcome:string} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome->value];
    }
}
