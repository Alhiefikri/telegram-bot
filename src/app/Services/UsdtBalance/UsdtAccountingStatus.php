<?php

namespace App\Services\UsdtBalance;

use Brick\Math\BigInteger;

/**
 * Read-only view of one accounting-aggregate consumer: how much of the
 * append-only accounting event stream is still pending, how much was claimed
 * and whether the balanced leg aggregates still cover every claim exactly.
 */
final readonly class UsdtAccountingStatus
{
    /**
     * @param  list<array{debit_account: string, credit_account: string, entries_count: int, amount_micro: string}>  $legs
     */
    public function __construct(
        public string $consumer,
        public int $pendingEvents,
        public int $claimedEvents,
        public int $aggregatedEvents,
        public BigInteger $claimedMicro,
        public BigInteger $aggregatedMicro,
        public array $legs,
    ) {}

    public function isConsistent(): bool
    {
        return $this->claimedEvents === $this->aggregatedEvents
            && $this->claimedMicro->isEqualTo($this->aggregatedMicro);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'consumer' => $this->consumer,
            'pending_events' => $this->pendingEvents,
            'claimed_events' => $this->claimedEvents,
            'aggregated_events' => $this->aggregatedEvents,
            'claimed_micro' => $this->claimedMicro->toString(),
            'aggregated_micro' => $this->aggregatedMicro->toString(),
            'consistent' => $this->isConsistent(),
            'legs' => $this->legs,
        ];
    }
}
