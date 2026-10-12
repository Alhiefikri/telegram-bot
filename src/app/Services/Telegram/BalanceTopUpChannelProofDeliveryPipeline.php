<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\PaidInvoice\PaidInvoiceTopUpPresentationAdapter;
use App\Storefront\StorefrontUrl;
use InvalidArgumentException;
use Throwable;

/**
 * Public Store Channel proof for a settled Store Balance top-up.
 *
 * Eligibility, masking, and rendering live in the PaidInvoice seam; the ledger
 * protocol owns every delivery transition, and the frozen effect target keeps
 * a later channel change from re-routing an already materialized post.
 */
final class BalanceTopUpChannelProofDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly SalesProofPostFormatter $formatter,
        private readonly PaidInvoiceRenderer $invoiceRenderer,
        private readonly PaidInvoiceTopUpPresentationAdapter $invoicePresentation,
        private readonly StorefrontUrl $urls,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::BalanceTopUpChannelProof->value) {
            throw new InvalidArgumentException('Unsupported Telegram balance top-up channel proof effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $topUp = StoreBalanceTopUp::query()
                ->with('customer')
                ->findOrFail($claimed->source_id);
            $setting = StoreChannelSetting::current();

            if (! $setting->sales_proof_enabled) {
                $this->finalizeNotDeliverable($claimed, 'proof_disabled', 'Sales Proof Post is disabled.');

                return;
            }

            if (trim((string) ($setting->telegram_chat_id ?: $setting->channel_username)) === '') {
                $this->finalizeNotDeliverable($claimed, 'proof_target_missing', 'Store Channel chat is not configured.');

                return;
            }

            if (! $this->eligible($topUp)) {
                $this->finalizeNotDeliverable(
                    $claimed,
                    'balance_top_up_proof_not_eligible',
                    'Store Balance channel proof requires a settled paid top-up.',
                );

                return;
            }

            $presentation = $this->invoicePresentation->present($topUp);
            $invoice = $this->invoiceRenderer->render($presentation);

            try {
                $response = $this->bot->sendPhoto(
                    (string) $claimed->telegram_chat_id,
                    $invoice->bytes,
                    $invoice->filename,
                    $this->formatter->formatTopUp($presentation),
                    [
                        'inline_keyboard' => [[[
                            'text' => $this->formatter->shoppingButtonLabel(),
                            'url' => $this->shoppingButtonUrl(),
                        ]]],
                    ],
                    [TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION => CustomEmojiCapability::SCOPE_CHANNEL],
                );
            } finally {
                unset($invoice, $presentation);
            }

            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram balance top-up channel proof');
            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
            );
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

    private function eligible(StoreBalanceTopUp $topUp): bool
    {
        return $topUp->status === StoreBalanceTopUpStatus::Paid
            && $topUp->settled_at !== null
            && $topUp->provider_total_payment !== null
            && (int) $topUp->amount > 0
            && strtoupper(trim((string) $topUp->currency)) === 'IDR'
            && in_array(strtoupper(trim((string) $topUp->provider_payment_method)), ['QRIS', 'QR_MANUAL'], true);
    }

    private function shoppingButtonUrl(): string
    {
        return $this->urls->telegramStartUrl();
    }

    private function finalizeNotDeliverable(TelegramOutboundEffect $effect, string $reason, string $message): void
    {
        $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
    }
}
