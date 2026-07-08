<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramAdminOrderNotifier;
use App\Services\Telegram\TelegramAdminPaidOrderNotifier;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminOrderPaidNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $orderId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramAdminOrderNotifier $pendingNotifier, TelegramAdminPaidOrderNotifier $paidNotifier): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.admin_paid_order_notification_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $order = Order::findOrFail($this->orderId);
            $pendingNotifier->deletePending($order);
            $paidNotifier->send($order);
        } finally {
            DogfoodTiming::log('dogfood.telegram.admin_paid_order_notification_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
