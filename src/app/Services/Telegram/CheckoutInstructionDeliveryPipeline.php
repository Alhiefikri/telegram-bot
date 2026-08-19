<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * Checkout is an effect-family adapter. The materializer supplies the durable
 * effect row; this module owns checkout source loading, eligibility, provider
 * selection, rendering, and projection synchronization.
 *
 * Claim ownership, leases, ambiguity classification, and token-checked state
 * transitions are deliberately delegated to the shared ledger protocol seam.
 */
final class CheckoutInstructionDeliveryPipeline
{
    /** @var list<string> */
    private const EFFECT_TYPES = [
        TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
        TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
        TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
        TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
    ];

    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function deliverOrder(
        string $orderId,
        string $effectType,
        ?string $effectId,
        TelegramCheckoutIntents $checkoutIntents,
    ): void {
        $this->assertCheckoutEffectType($effectType);

        $effect = $effectId === null
            ? $this->outboundEffects->forOrder($orderId, $effectType)
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', Order::class)
                ->where('source_id', $orderId)
                ->where('effect_type', $effectType)
                ->firstOrFail();

        if ($effect === null) {
            $effect = $this->outboundEffects->materializeCheckoutEffectForOrder($orderId, $effectType);
        }

        if ($effect !== null) {
            $this->deliver($effect, $checkoutIntents);
        }
    }

    public function deliver(TelegramOutboundEffect $effect, TelegramCheckoutIntents $checkoutIntents): void
    {
        $this->assertCheckoutEffectType((string) $effect->effect_type);

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()->with(['customer', 'orderItems'])->findOrFail($claimed->source_id);
            if ($order->order_status !== OrderStatus::PendingPayment
                || ($order->expires_at !== null && $order->expires_at->lte(now()))) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $order->expires_at !== null && $order->expires_at->lte(now())
                        ? 'checkout_expired'
                        : 'checkout_not_pending',
                );

                return;
            }

            $payment = $this->paymentForEffect($order, $claimed);
            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $payment === null ? 'checkout_payment_missing' : 'checkout_payment_not_pending',
                );

                return;
            }

            $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => $this->timingStep($claimed),
                'payment_mode' => $this->timingPaymentMode($claimed),
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => $this->sendInstructions($claimed, $checkoutIntents, $order, $payment));
            $messageId = $this->responseValidator->messageIdFromResponse(
                $response,
                'Telegram checkout instruction',
            );

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            );
            if ($finalized === null) {
                return;
            }

            $this->synchronizeSentProjection($finalized, $messageId, $finalized->sent_at ?? now());

            if ($claimed->effect_type === TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT) {
                $terminalOrder = $order->fresh(['payments']);
                if (in_array($terminalOrder->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                    DeleteBuyerBinanceCheckoutInstruction::dispatch($terminalOrder->id, now()->toISOString())
                        ->onQueue('notifications');
                }
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );

                return;
            }

            if ($this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            )) {
                throw $exception;
            }
        }
    }

    private function paymentForEffect(Order $order, TelegramOutboundEffect $effect): ?Payment
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('method', 'QR_MANUAL')
                ->latest('created_at')
                ->first(),
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->latest('created_at')
                ->first(),
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::PAKASIR)
                ->latest('created_at')
                ->first(),
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('method', 'BINANCE_PAY_ID')
                ->latest('created_at')
                ->first(),
            default => null,
        };
    }

    private function sendInstructions(
        TelegramOutboundEffect $effect,
        TelegramCheckoutIntents $checkoutIntents,
        Order $order,
        Payment $payment,
    ): array {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => $checkoutIntents->sendPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
            ),
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => $checkoutIntents->sendKlikQrisPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
            ),
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => $checkoutIntents->sendPakasirPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
            ),
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => $checkoutIntents->sendBinancePaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
                $order->customer ?? throw new RuntimeException('Checkout customer is missing.'),
            ),
            default => throw new RuntimeException('Unsupported Telegram checkout effect type.'),
        };
    }

    private function timingStep(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => 'manual_payment_instructions',
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => 'klikqris_payment_instructions',
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => 'pakasir_payment_instructions',
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => 'binance_payment_instructions',
            default => 'checkout_payment_instructions',
        };
    }

    private function timingPaymentMode(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => PaymentProvider::MANUAL,
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => PaymentProvider::KLIKQRIS,
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => PaymentProvider::PAKASIR,
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => 'binance',
            default => 'unknown',
        };
    }

    private function assertCheckoutEffectType(string $effectType): void
    {
        if (! in_array($effectType, self::EFFECT_TYPES, true)) {
            throw new RuntimeException('Unsupported Telegram checkout effect type.');
        }
    }

    private function synchronizeSentProjection(
        TelegramOutboundEffect $effect,
        string $messageId,
        CarbonInterface $at,
    ): void {
        if (! in_array($effect->effect_type, self::EFFECT_TYPES, true)) {
            return;
        }

        Order::query()
            ->whereKey($effect->source_id)
            ->update([
                'manual_checkout_customer_notified_at' => $at,
                'manual_checkout_customer_notification_chat_id' => $effect->telegram_chat_id,
                'manual_checkout_customer_notification_message_id' => $this->responseValidator->isValidMessageId($messageId) ? (int) $messageId : null,
                'manual_checkout_customer_notification_deleted_at' => null,
                'manual_checkout_customer_notification_deletion_started_at' => null,
                'manual_checkout_customer_notification_deletion_token' => null,
                'updated_at' => now(),
            ]);
    }
}
