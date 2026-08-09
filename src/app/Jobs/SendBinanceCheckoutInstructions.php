<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
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

class SendBinanceCheckoutInstructions implements ShouldBeUnique, ShouldQueue
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

        DogfoodTiming::log('dogfood.telegram.binance_checkout_instructions_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $claimed = DB::transaction(function (): ?Order {
                $order = Order::whereKey($this->orderId)->lockForUpdate()->firstOrFail();
                if ($order->manual_checkout_customer_notified_at !== null
                    || $order->order_status !== OrderStatus::PendingPayment
                    || $this->hasActiveClaim($order->manual_checkout_customer_notification_started_at)) {
                    return null;
                }

                $order->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();

                return $order->fresh(['customer', 'orderItems', 'payments']);
            });
            if ($claimed === null) {
                return;
            }
            $order = $claimed;
            $messageId = null;

            try {
                $payment = $order->payments->first(fn ($payment): bool => $payment->provider === 'manual' && $payment->method === 'BINANCE_PAY_ID');
                if ($payment === null) {
                    throw new \RuntimeException('Binance payment is missing for order '.$order->id.'.');
                }

                $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                    'step' => 'binance_payment_instructions',
                    'payment_mode' => 'manual',
                    'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                    'order_correlation_id' => DogfoodTiming::correlation($order->id),
                ], fn () => $checkoutIntents->sendBinancePaymentInstructions(
                    (string) $order->customer->telegram_id,
                    $order,
                    $payment,
                    $order->customer,
                ));
                $messageId = data_get($response, 'result.message_id');
                if (! is_numeric($messageId)) {
                    throw new \RuntimeException('Telegram Binance checkout instruction did not return a message ID.');
                }
            } catch (\Throwable $e) {
                $order->forceFill(['manual_checkout_customer_notification_started_at' => null])->save();

                throw $e;
            }

            $order->forceFill([
                'manual_checkout_customer_notified_at' => now(),
                'manual_checkout_customer_notification_started_at' => null,
                'manual_checkout_customer_notification_chat_id' => (string) $order->customer->telegram_id,
                'manual_checkout_customer_notification_message_id' => (int) $messageId,
            ])->save();

            $terminalOrder = $order->fresh(['payments']);
            if (in_array($terminalOrder->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
                && $terminalOrder->payments->contains(fn ($payment): bool => $payment->provider === 'manual' && $payment->method === 'BINANCE_PAY_ID')) {
                DeleteBuyerBinanceCheckoutInstruction::dispatch($terminalOrder->id, now()->toISOString())
                    ->onQueue('notifications');
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.binance_checkout_instructions_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
