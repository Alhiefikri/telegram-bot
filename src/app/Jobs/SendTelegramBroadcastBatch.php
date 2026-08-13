<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBroadcastService;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramBroadcastBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $broadcastId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return (string) $this->broadcastId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TelegramBroadcastService $broadcasts): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        $result = null;
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.admin_broadcast_job_picked_up', [
            'broadcast_id' => $this->broadcastId,
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $result = $broadcasts->processAdminBroadcastBatch($this->broadcastId);

            if ($result->hasMore) {
                $delay = $result->retryAfterSeconds !== null
                    ? now()->addSeconds($result->retryAfterSeconds)
                    : ($result->transientFailures > 0 || $result->processed === 0 ? now()->addSeconds(30) : now());

                self::dispatch($this->broadcastId, now()->toISOString())
                    ->onQueue('telegram-broadcast')
                    ->delay($delay);
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.admin_broadcast_job_finished', DogfoodTiming::queryStats() + [
                'broadcast_id' => $this->broadcastId,
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'processed_recipients' => $result?->processed,
                'sent' => $result?->sent,
                'transient_failures' => $result?->transientFailures,
                'terminal_failures' => $result?->terminalFailures,
                'has_more' => $result?->hasMore,
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
