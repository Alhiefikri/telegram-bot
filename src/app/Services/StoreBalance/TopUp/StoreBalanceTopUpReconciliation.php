<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;

final readonly class StoreBalanceTopUpReconciliation
{
    public function __construct(
        private StoreBalanceTopUpLifecycleResult $result,
        private ?StoreBalanceTopUpSettlementEvidence $evidence = null,
        private ?string $eventId = null,
    ) {}

    public static function state(StoreBalanceTopUpOutcome $outcome): self
    {
        return new self(StoreBalanceTopUpLifecycleResult::state($outcome));
    }

    public function result(): StoreBalanceTopUpLifecycleResult
    {
        return $this->result;
    }

    public function evidence(): ?StoreBalanceTopUpSettlementEvidence
    {
        return $this->evidence;
    }

    public function eventId(): ?string
    {
        return $this->eventId;
    }

    public function hasEvidence(): bool
    {
        return $this->evidence !== null;
    }
}
