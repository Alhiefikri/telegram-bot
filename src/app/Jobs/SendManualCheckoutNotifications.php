<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendManualCheckoutNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $orderId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramCheckoutIntents $checkoutIntents): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.manual_checkout_notifications_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $order = Order::with(['customer', 'orderItems'])->findOrFail($this->orderId);
            $chatId = (string) $order->customer->telegram_id;

            $claimed = DB::transaction(function () use ($order): ?Order {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->manual_checkout_customer_notified_at !== null
                    || $locked->manual_checkout_customer_notification_started_at !== null) {
                    return null;
                }

                $locked->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();

                return $locked->fresh(['customer', 'orderItems']);
            });
            if ($claimed !== null) {
                $order = $claimed;
                $chatId = (string) $order->customer->telegram_id;
                // Claim before Telegram I/O. If the worker dies after Telegram accepts
                // the photo but before notified_at is written, retries skip this
                // customer effect instead of sending duplicate payment instructions.
                try {
                    DogfoodTiming::time('dogfood.telegram.checkout_step', [
                        'step' => 'manual_payment_instructions',
                        'payment_mode' => 'manual',
                        'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                        'order_correlation_id' => DogfoodTiming::correlation($order->id),
                    ], fn () => $checkoutIntents->sendPaymentInstructions($chatId, $order));
                } catch (\Throwable $e) {
                    $order->forceFill(['manual_checkout_customer_notification_started_at' => null])->save();

                    throw $e;
                }

                $order->forceFill(['manual_checkout_customer_notified_at' => now()])->save();
            }

        } finally {
            DogfoodTiming::log('dogfood.telegram.manual_checkout_notifications_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
