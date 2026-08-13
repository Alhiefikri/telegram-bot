<?php

namespace App\Services\Telegram;

final readonly class TelegramBroadcastBatchResult
{
    public function __construct(
        public int $processed,
        public int $sent,
        public int $transientFailures,
        public int $terminalFailures,
        public bool $hasMore,
        public ?int $retryAfterSeconds = null,
    ) {}
}
