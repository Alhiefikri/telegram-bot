<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendPakasirCheckoutInstructions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $orderId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null ? $this->orderId : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramCheckoutIntents $checkoutIntents, ?TelegramOutboundEffectService $outboundEffects = null): void
    {
        $outboundEffects ??= app(TelegramOutboundEffectService::class);
        $effect = $this->effectId === null
            ? $outboundEffects->forOrder($this->orderId, TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT)
            : TelegramOutboundEffect::query()
                ->whereKey($this->effectId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT)
                ->firstOrFail();

        if ($effect !== null) {
            $outboundEffects->processCheckout($effect, $checkoutIntents);

            return;
        }

        $this->handleLegacy($checkoutIntents);
    }

    private function handleLegacy(TelegramCheckoutIntents $checkoutIntents): void
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
            $payment = $order->payments->firstWhere('provider', 'pakasir');
            if ($payment === null) {
                throw new \RuntimeException('Pakasir payment is missing for order '.$order->id.'.');
            }

            $checkoutIntents->sendPakasirPaymentInstructions(
                (string) $order->customer->telegram_id,
                $order,
                $payment,
            );
        } catch (\Throwable $e) {
            $order->forceFill(['manual_checkout_customer_notification_started_at' => null])->save();

            throw $e;
        }

        $order->forceFill([
            'manual_checkout_customer_notified_at' => now(),
            'manual_checkout_customer_notification_started_at' => null,
        ])->save();
    }

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
