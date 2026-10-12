<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\InventoryLedger;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Storefront\StorefrontUrl;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Adapter for the public Store Channel Sales Proof delivery. The Sales Proof
 * row is a read projection; only the ledger protocol owns delivery state.
 */
final class SalesProofDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly SalesProofPostFormatter $formatter,
        private readonly SalesProofPostNumbering $numbering,
        private readonly SalesProofStockSignal $stockSignal,
        private readonly InventoryLedger $inventory,
        private readonly StorefrontUrl $urls,
        private readonly PaidInvoiceRenderer $invoiceRenderer,
        private readonly PaidInvoiceOrderPresentationAdapter $invoicePresentation,
        private readonly SalesProofEffectNormalizer $effectNormalizer,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::SalesProofPost->value) {
            throw new InvalidArgumentException('Unsupported Telegram Sales Proof effect type.');
        }

        $effect = $this->effectNormalizer->normalize($effect);
        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems', 'salesProofPost', 'payments'])
                ->findOrFail($claimed->source_id);
            $setting = StoreChannelSetting::current();
            $chatId = trim((string) ($setting->telegram_chat_id ?: $setting->channel_username));

            if (! $setting->sales_proof_enabled) {
                $this->finalizeNotDeliverable($claimed, 'proof_disabled', 'Sales Proof Post is disabled.');

                return;
            }

            if ($chatId === '') {
                $this->finalizeNotDeliverable($claimed, 'proof_target_missing', 'Store Channel chat is not configured.');

                return;
            }

            $invariantFailure = $this->paidOrderProofInvariantFailure($order);
            if ($invariantFailure !== null) {
                $this->finalizeNotDeliverable($claimed, 'proof_not_eligible', $invariantFailure);

                return;
            }

            if ($this->isPhotoEffect($claimed)) {
                $presentation = $this->invoicePresentation->present($order);
                $invoice = $this->invoiceRenderer->render($presentation);
                $remainingStock = $this->stockSignal->forOrder($order);
                $postNumber = $this->numbering->reserveNextNumber();

                try {
                    $response = $this->bot->sendPhoto(
                        (string) $claimed->telegram_chat_id,
                        $invoice->bytes,
                        $invoice->filename,
                        $this->formatter->format($presentation, $postNumber, $remainingStock),
                        [
                            'inline_keyboard' => [[[
                                'text' => $this->formatter->shoppingButtonLabel(),
                                'url' => $this->salesProofShoppingButtonUrl($order),
                            ]]],
                        ],
                        [TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION => CustomEmojiCapability::SCOPE_CHANNEL],
                    );
                } finally {
                    unset($invoice, $presentation);
                }
            } else {
                $postNumber = $this->numbering->reserveNextNumber();
                $response = $this->bot->sendMessage(
                    (string) $claimed->telegram_chat_id,
                    $this->formatter->formatLegacy($order, $postNumber),
                    [
                        'inline_keyboard' => [[[
                            'text' => $this->formatter->shoppingButtonLabel(),
                            'url' => $this->salesProofShoppingButtonUrl($order),
                        ]]],
                    ],
                    [TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION => CustomEmojiCapability::SCOPE_CHANNEL],
                );
            }
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram Sales Proof');
            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
            );

            if ($finalized !== null) {
                $this->synchronizeSentProjection($finalized);
                Log::info('telegram.sales_proof_effect_sent', [
                    'effect_id' => (string) $finalized->getKey(),
                    'source_correlation_id' => DogfoodTiming::correlation($finalized->source_id),
                    'attempts' => (int) $finalized->attempts,
                    'telegram_message_id' => (string) $finalized->telegram_message_id,
                ]);
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $finalized = $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                if ($finalized !== null) {
                    $this->synchronizeUnknownProjection($finalized);
                }
                $this->logDeliveryFailure($finalized ?? $claimed, $exception, 'unknown');

                return;
            }

            $finalized = $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );
            if ($finalized !== null) {
                $this->synchronizeFailedProjection($finalized);
            }
            $this->logDeliveryFailure($finalized ?? $claimed, $exception, 'failed');
        }
    }

    public function synchronizeRecoveredUnknown(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type === TelegramOutboundEffectType::SalesProofPost->value) {
            $this->synchronizeUnknownProjection($effect);
        }
    }

    /**
     * Repair Sales Proof Post projections for effects already finalized as sent.
     *
     * Recovery only examines sent ledger rows; it never claims or dispatches an
     * effect. Conditional projection updates make repeated recovery idempotent.
     */
    public function reconcileSentProjections(int $chunk = 100): int
    {
        $repaired = 0;
        $effects = app(TelegramOutboundRecoveryPage::class)->load(
            'sent_sales_proof_projections',
            TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->where('effect_type', TelegramOutboundEffectType::SalesProofPost->value)
                ->where('state', TelegramOutboundEffectState::Sent->value),
            max(1, $chunk),
        );

        foreach ($effects as $effect) {
            if ($this->synchronizeSentProjection($effect)) {
                $repaired++;
            }
        }

        return $repaired;
    }

    private function paidOrderProofInvariantFailure(Order $order): ?string
    {
        if (app(SupplierFulfillmentService::class)->hasPending($order)) {
            return 'Order fulfillment is processing.';
        }
        $isPaid = $order->payment_status === PaymentStatus::Paid
            && $order->payments->contains(fn ($payment): bool => $payment->status === PaymentStatus::Paid);
        $isProcessable = in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
        if (! $isPaid || ! $isProcessable) {
            return 'Sales Proof Post requires a paid processable order.';
        }

        return $this->inventory->hasSaleProofForOrder($order)
            ? null
            : 'Sales Proof Post requires matching inventory sale proof.';
    }

    private function salesProofShoppingButtonUrl(Order $order): string
    {
        $productId = $order->orderItems->first()?->product_id;

        return $productId
            ? $this->urls->telegramProductStartUrlForId((int) $productId)
            : $this->urls->telegramStartUrl();
    }

    private function isPhotoEffect(TelegramOutboundEffect $effect): bool
    {
        return $effect->telegram_method === 'sendPhoto'
            && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_SALES_PROOF;
    }

    private function synchronizeSentProjection(TelegramOutboundEffect $effect): bool
    {
        $messageId = (string) $effect->telegram_message_id;
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return false;
        }

        $sentAt = $effect->sent_at ?? now();
        $attempts = (int) $effect->attempts;

        return SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->where(function ($post) use ($messageId, $sentAt, $attempts): void {
                $post
                    ->where('status', '<>', SalesProofPost::STATUS_SENT)
                    ->orWhere('attempts', '<>', $attempts)
                    ->orWhereNull('telegram_message_id')
                    ->orWhere('telegram_message_id', '<>', $messageId)
                    ->orWhereNull('sent_at')
                    ->orWhere('sent_at', '<>', $sentAt)
                    ->orWhereNotNull('last_error');
            })
            ->update([
                'status' => SalesProofPost::STATUS_SENT,
                'attempts' => $attempts,
                'telegram_message_id' => $messageId,
                'sent_at' => $sentAt,
                'last_error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    private function synchronizeUnknownProjection(TelegramOutboundEffect $effect): void
    {
        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->update([
                'status' => SalesProofPost::STATUS_UNKNOWN,
                'attempts' => (int) $effect->attempts,
                'last_error' => mb_substr($effect->last_error ?: 'Sales Proof delivery outcome is unknown and requires review.', 0, 1000),
                'updated_at' => $effect->unknown_at ?? now(),
            ]);
    }

    private function synchronizeFailedProjection(TelegramOutboundEffect $effect): void
    {
        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->whereNotIn('status', [
                SalesProofPost::STATUS_SENT,
                SalesProofPost::STATUS_UNKNOWN,
                SalesProofPost::STATUS_MANUAL_REVIEW,
            ])
            ->update([
                'status' => SalesProofPost::STATUS_FAILED,
                'attempts' => (int) $effect->attempts,
                'last_error' => mb_substr($effect->last_error ?: 'Sales Proof delivery failed before completion.', 0, 1000),
                'updated_at' => now(),
            ]);
    }

    private function finalizeNotDeliverable(TelegramOutboundEffect $effect, string $reason, string $message): void
    {
        $finalized = $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
        if ($finalized !== null) {
            $this->synchronizeFailedProjection($finalized);
            Log::warning('telegram.sales_proof_effect_not_deliverable', [
                'effect_id' => (string) $finalized->getKey(),
                'source_correlation_id' => DogfoodTiming::correlation($finalized->source_id),
                'attempts' => (int) $finalized->attempts,
                'reason' => $reason,
            ]);
        }
    }

    private function logDeliveryFailure(
        TelegramOutboundEffect $effect,
        Throwable $exception,
        string $state,
    ): void {
        Log::warning('telegram.sales_proof_effect_failed', [
            'effect_id' => (string) $effect->getKey(),
            'source_correlation_id' => DogfoodTiming::correlation($effect->source_id),
            'attempts' => (int) $effect->attempts,
            'state' => $state,
            'last_error_code' => $effect->last_error_code,
            'http_status' => $exception instanceof RequestException ? $exception->response?->status() : null,
            'exception' => $exception::class,
        ]);
    }
}
