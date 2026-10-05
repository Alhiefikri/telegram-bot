<?php

namespace App\Services\MoneyReceipts;

final readonly class ExternalMoneyReceiptBackfillResult
{
    public function __construct(
        public string $source,
        public bool $dryRun,
        public bool $complete,
        public bool $ready,
        public int $scanned,
        public int $claimed,
        public int $replayed,
        public int $reserved,
        public int $conflicts,
        public int $reviews,
        public int $skipped,
        public ?string $cursor,
    ) {}
}
