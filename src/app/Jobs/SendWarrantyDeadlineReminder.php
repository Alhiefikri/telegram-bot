<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Warranty\WarrantyDeadlineReminderPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendWarrantyDeadlineReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(WarrantyDeadlineReminderPipeline $reminders): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $reminders->deliver($effect);
        }
    }
}
