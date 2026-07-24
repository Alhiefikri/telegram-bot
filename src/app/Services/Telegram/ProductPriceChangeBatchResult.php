<?php

namespace App\Services\Telegram;

final readonly class ProductPriceChangeBatchResult
{
    public function __construct(
        public int $processed,
        public int $transientFailures,
        public int $terminalFailures,
        public bool $hasMore,
    ) {}
}
