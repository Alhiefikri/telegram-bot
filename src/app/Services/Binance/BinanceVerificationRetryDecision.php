<?php

namespace App\Services\Binance;

final readonly class BinanceVerificationRetryDecision
{
    public function __construct(
        public BinanceVerificationResult $result,
        public ?int $delaySeconds = null,
        public bool $terminal = false,
    ) {}

    public static function of(BinanceVerificationResult $result, ?int $delaySeconds = null, bool $terminal = false): self
    {
        return new self($result, $delaySeconds, $terminal);
    }

    public function shouldRetry(): bool
    {
        return ! $this->terminal && $this->delaySeconds !== null;
    }
}
