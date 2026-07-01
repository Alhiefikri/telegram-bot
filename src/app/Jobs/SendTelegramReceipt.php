<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $orderId, public bool $force = false, public ?string $jobEnqueuedAt = null) {}

    public function handle(TelegramReceiptNotifier $notifier): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.receipt_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $notifier->send(Order::findOrFail($this->orderId), force: $this->force);
        } finally {
            DogfoodTiming::log('dogfood.telegram.receipt_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
