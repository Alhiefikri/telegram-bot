<?php

namespace App\Services\Binance;

final readonly class BinanceVerificationStatus
{
    public function __construct(
        public BinanceVerificationOutcome $outcome,
        public string $mode,
        public bool $automatic,
        public bool $submitted,
        public bool $withinGrace,
    ) {}

    public function closed(): bool
    {
        return $this->mode === 'order_id' && $this->submitted && ! $this->withinGrace;
    }
}
