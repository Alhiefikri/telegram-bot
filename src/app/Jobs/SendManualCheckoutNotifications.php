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

            if ($order->manual_checkout_customer_notified_at === null) {
                DogfoodTiming::time('dogfood.telegram.checkout_step', [
                    'step' => 'manual_payment_instructions',
                    'payment_mode' => 'manual',
                    'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                    'order_correlation_id' => DogfoodTiming::correlation($order->id),
                ], fn () => $checkoutIntents->sendPaymentInstructions($chatId, $order));

                $order->forceFill(['manual_checkout_customer_notified_at' => now()])->save();
            }

            if ($order->fresh()->manual_checkout_admin_notified_at === null) {
                DogfoodTiming::time('dogfood.telegram.checkout_step', [
                    'step' => 'manual_admin_notification',
                    'payment_mode' => 'manual',
                    'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                    'order_correlation_id' => DogfoodTiming::correlation($order->id),
                ], fn () => $checkoutIntents->notifyAdminAboutManualOrder($order));

                $order->forceFill(['manual_checkout_admin_notified_at' => now()])->save();
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
