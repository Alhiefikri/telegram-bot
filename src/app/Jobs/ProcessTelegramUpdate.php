<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramUpdate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $telegramUpdateId) {}

    public function uniqueId(): string
    {
        return (string) $this->telegramUpdateId;
    }

    public function handle(TelegramUpdateProcessor $processor): void
    {
        $processor->process(TelegramUpdate::findOrFail($this->telegramUpdateId));
    }
}
