<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramAdminOrderNotifier;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DeleteAdminPendingOrderNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $orderId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    /**
     * Keep the unique lock outside the order transaction's database connection.
     *
     * Laravel acquires unique job locks when the PendingDispatch is destroyed,
     * before an afterCommit callback enqueues the job. A database cache lock can
     * therefore abort a competing lifecycle transaction on a unique-key race.
     */
    public function uniqueVia(): Repository
    {
        if (env('APP_ENV') === 'testing') {
            $path = sys_get_temp_dir().'/telegram-bot-cache-'.getmypid();

            return Cache::build([
                'driver' => 'file',
                'path' => $path,
                'lock_path' => $path,
            ]);
        }

        return Cache::driver('file');
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramAdminOrderNotifier $notifier): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.admin_pending_order_notification_delete_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $notifier->deletePending(Order::findOrFail($this->orderId));
        } finally {
            DogfoodTiming::log('dogfood.telegram.admin_pending_order_notification_delete_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
