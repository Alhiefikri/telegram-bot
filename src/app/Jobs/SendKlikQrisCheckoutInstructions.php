<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Telegram\TelegramCheckoutIntents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendKlikQrisCheckoutInstructions implements ShouldBeUnique, ShouldQueue
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
        $order = DB::transaction(function (): ?Order {
            $order = Order::whereKey($this->orderId)->lockForUpdate()->firstOrFail();
            if ($order->manual_checkout_customer_notified_at !== null
                || $order->order_status !== OrderStatus::PendingPayment
                || $this->hasActiveClaim($order->manual_checkout_customer_notification_started_at)) {
                return null;
            }

            $order->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();

            return $order->fresh(['customer', 'orderItems', 'payments']);
        });

        if ($order === null) {
            return;
        }

        try {
            $payment = $order->payments->firstWhere('provider', 'klikqris');
            if ($payment === null) {
                throw new \RuntimeException('KlikQRIS payment is missing for order '.$order->id.'.');
            }

            $response = $checkoutIntents->sendKlikQrisPaymentInstructions(
                (string) $order->customer->telegram_id,
                $order,
                $payment,
            );
            $messageId = data_get($response, 'result.message_id');
            if (! is_numeric($messageId)) {
                throw new \RuntimeException('Telegram KlikQRIS checkout instruction did not return a message ID.');
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
            'manual_checkout_customer_notification_deleted_at' => null,
            'manual_checkout_customer_notification_deletion_started_at' => null,
            'manual_checkout_customer_notification_deletion_token' => null,
        ])->save();
    }

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
