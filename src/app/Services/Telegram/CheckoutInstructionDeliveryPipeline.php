<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
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
    public const EFFECT_TYPES = [
        TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
        TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
        TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
        TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
        TelegramOutboundEffectService::TYPE_BSC_CHECKOUT,
        TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT,
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
            $order = Order::query()->with(['customer', 'orderItems', 'payments'])->findOrFail($claimed->source_id);
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
            $deliveryMetadata = $this->deliveryMetadata($claimed, $response);
            $messageId = $this->responseValidator->messageIdFromResponse(
                $response,
                'Telegram checkout instruction',
            );

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
                $deliveryMetadata,
            );
            if ($finalized === null) {
                return;
            }

            $this->synchronizeSentProjection($finalized, $messageId, $finalized->sent_at ?? now());
            $terminalOrder = Order::query()
                ->select(['id', 'order_status', 'telegram_receipt_sent_at'])
                ->findOrFail($order->id);
            if ($terminalOrder->order_status === OrderStatus::Cancelled
                || ($terminalOrder->order_status === OrderStatus::Completed && $terminalOrder->telegram_receipt_sent_at !== null)) {
                DeleteBuyerCheckoutMessages::dispatch($terminalOrder->id, now()->toISOString())
                    ->onQueue('notifications');

                return;
            }

            if (in_array($claimed->effect_type, [
                TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
                TelegramOutboundEffectService::TYPE_BSC_CHECKOUT,
            ], true)) {
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

    /**
     * Repair Order projections for checkout effects already finalized as sent.
     *
     * Recovery only examines sent ledger rows; it never claims or dispatches an
     * effect. The conditional projection update fences concurrent recovery
     * scans and keeps repeated recovery idempotent.
     */
    public function reconcileSentProjections(int $chunk = 100): int
    {
        $repaired = 0;
        $effects = app(TelegramOutboundRecoveryPage::class)->load(
            'sent_checkout_instruction_projections',
            TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->whereIn('effect_type', self::EFFECT_TYPES)
                ->where('state', TelegramOutboundEffectState::Sent->value)
                ->whereNotNull('telegram_message_id'),
            max(1, $chunk),
        );

        foreach ($effects as $effect) {
            if ($this->synchronizeSentProjection(
                $effect,
                (string) $effect->telegram_message_id,
                $effect->sent_at ?? now(),
            )) {
                $repaired++;
            }
        }

        return $repaired;
    }

    private function paymentForEffect(Order $order, TelegramOutboundEffect $effect): ?Payment
    {
        if (! $order->relationLoaded('payments')) {
            return null;
        }

        $payments = $order->getRelation('payments')->sortByDesc('created_at');

        $matches = match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL
                && $payment->method === 'QR_MANUAL',
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::KLIKQRIS,
            TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::SAKURUPIAH
                && strtoupper((string) $payment->method) === SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::PAKASIR,
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL
                && $payment->method === 'BINANCE_PAY_ID',
            TelegramOutboundEffectService::TYPE_BSC_CHECKOUT => static fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL
                && $payment->method === 'USDT_BEP20',
            default => null,
        };

        $payment = $matches === null ? null : $payments->first($matches);
        if ($payment !== null) {
            $payment->setRelation('order', $order);
        }

        return $payment;
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
            TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT => $checkoutIntents->sendSakurupiahPaymentInstructions(
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
            TelegramOutboundEffectService::TYPE_BSC_CHECKOUT => $checkoutIntents->sendBscPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
                $order->customer ?? throw new RuntimeException('Checkout customer is missing.'),
            ),
            default => throw new RuntimeException('Unsupported Telegram checkout effect type.'),
        };
    }

    /** @param array<mixed> $response
     * @return array<string, string>
     */
    private function deliveryMetadata(TelegramOutboundEffect $effect, array $response): array
    {
        if (! in_array($effect->effect_type, [
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
        ], true)) {
            return [];
        }

        $telegramResult = $response['result'] ?? null;
        $hasPhoto = is_array($telegramResult) && is_array($telegramResult['photo'] ?? null) && $telegramResult['photo'] !== [];
        $isBinance = $effect->effect_type === TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT;
        $outcome = match (true) {
            $isBinance && $hasPhoto => 'binance_photo_sent',
            $isBinance => 'binance_text_fallback_sent',
            $hasPhoto => 'qris_sent',
            default => 'retry_notice_sent',
        };

        return [
            'delivery_outcome' => $outcome,
            'delivery_method' => $hasPhoto ? 'sendPhoto' : 'sendMessage',
        ];
    }

    private function timingStep(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => 'manual_payment_instructions',
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => 'klikqris_payment_instructions',
            TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT => 'sakurupiah_payment_instructions',
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => 'pakasir_payment_instructions',
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => 'binance_payment_instructions',
            TelegramOutboundEffectService::TYPE_BSC_CHECKOUT => 'bsc_payment_instructions',
            default => 'checkout_payment_instructions',
        };
    }

    private function timingPaymentMode(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT => PaymentProvider::MANUAL,
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT => PaymentProvider::KLIKQRIS,
            TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT => PaymentProvider::SAKURUPIAH,
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT => PaymentProvider::PAKASIR,
            TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT => 'binance',
            TelegramOutboundEffectService::TYPE_BSC_CHECKOUT => 'bsc',
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
    ): bool {
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return false;
        }

        return Order::query()
            ->whereKey($effect->source_id)
            ->whereNull('manual_checkout_customer_notified_at')
            ->update([
                'manual_checkout_customer_notified_at' => $at,
                'manual_checkout_customer_notification_chat_id' => $effect->telegram_chat_id,
                'manual_checkout_customer_notification_message_id' => (int) $messageId,
                'manual_checkout_customer_notification_deleted_at' => null,
                'manual_checkout_customer_notification_deletion_started_at' => null,
                'manual_checkout_customer_notification_deletion_token' => null,
                'updated_at' => now(),
            ]) === 1;
    }
}
