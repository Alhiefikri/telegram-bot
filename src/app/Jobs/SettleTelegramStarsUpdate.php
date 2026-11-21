<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramStarsUpdate;
use App\Services\TelegramStars\TelegramStarsRefunds;
use App\Services\TelegramStars\TelegramStarsSettlement;
use App\Support\QueueTopology;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SettleTelegramStarsUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public readonly int $inboxId)
    {
        $this->onQueue(QueueTopology::PAYMENTS);
    }

    public function handle(TelegramStarsSettlement $settlement, TelegramStarsRefunds $refunds): void
    {
        match (TelegramUpdate::whereKey($this->inboxId)->value('stars_event')) {
            TelegramStarsUpdate::SUCCESS => $settlement->process($this->inboxId),
            TelegramStarsUpdate::REFUND => $refunds->process($this->inboxId),
            default => null,
        };
    }
}
