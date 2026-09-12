<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use Throwable;

final class BalanceTopUpInstructionDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly StoreBalanceTopUpService $topUps,
        private readonly TelegramPaymentInstructionRenderer $renderer,
    ) {}

    public function deliver(string $topUpId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? $this->outboundEffects->forBalanceTopUp($topUpId)
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
                ->firstOrFail();

        if ($effect === null) {
            return;
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $topUp = StoreBalanceTopUp::query()
                ->with('customer')
                ->whereKey($claimed->source_id)
                ->first();
            if ($topUp === null) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_missing',
                );

                return;
            }

            if ($topUp->status === StoreBalanceTopUpStatus::Paid
                || $topUp->status === StoreBalanceTopUpStatus::Expired) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    ($topUp->provider_expires_at !== null && ! $topUp->provider_expires_at->isFuture())
                        || ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture())
                        ? 'balance_top_up_expired'
                        : 'balance_top_up_not_payable',
                );

                return;
            }

            $presentation = $this->topUps->presentation($topUp);
            $payable = $presentation->payable();
            if ($payable === null
                || (! $presentation->isPayable() && ! $presentation->isAwaitingConfirmation())) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_not_payable',
                );

                return;
            }

            $attachment = $this->topUps->instructionAttachment($topUp, $payable);
            $locale = (string) ($claimed->locale ?: $topUp->locale ?: $topUp->customer?->locale ?: 'en');
            $instruction = $this->renderer->balanceTopUp(
                $topUp,
                $payable,
                $attachment->photoContents(),
                $locale,
                awaitingConfirmation: $presentation->isAwaitingConfirmation(),
            );
            $response = $this->bot->sendPhoto(
                (string) $claimed->telegram_chat_id,
                $instruction->photoContents,
                $instruction->filename,
                $instruction->caption(),
                $instruction->replyMarkup,
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance top-up instruction');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_top_up',
                    'attachment_type' => 'qris_png',
                    'delivery_method' => 'sendPhoto',
                ],
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
}
