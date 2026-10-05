<?php

namespace App\Services\UsdtBalance;

final readonly class UsdtAccountingAggregationResult
{
    public function __construct(
        public string $consumer,
        public int $processedEvents,
        public ?int $lastEventId,
    ) {}
}
