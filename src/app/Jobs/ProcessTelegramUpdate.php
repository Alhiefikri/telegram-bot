<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $telegramUpdateId, public ?string $jobEnqueuedAt = null) {}

    public function handle(TelegramUpdateProcessor $processor): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.job_picked_up', [
            'telegram_update_id' => $this->telegramUpdateId,
            'correlation_id' => DogfoodTiming::correlation($this->telegramUpdateId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $processor->process(TelegramUpdate::findOrFail($this->telegramUpdateId));
        } finally {
            DogfoodTiming::log('dogfood.telegram.handler_finished', DogfoodTiming::queryStats() + [
                'telegram_update_id' => $this->telegramUpdateId,
                'correlation_id' => DogfoodTiming::correlation($this->telegramUpdateId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
