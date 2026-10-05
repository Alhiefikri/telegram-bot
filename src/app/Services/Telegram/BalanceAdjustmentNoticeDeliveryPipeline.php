<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramOutboundEffect;
use InvalidArgumentException;
use Throwable;

final class BalanceAdjustmentNoticeDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responses,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::BalanceAdjustmentNotice->value) {
            throw new InvalidArgumentException('Unsupported balance adjustment effect.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $message = $claimed->outcome_metadata['message'] ?? null;
            if (! is_string($message) || $message === '') {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'balance_adjustment_notice_missing');

                return;
            }
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $message, options: ['parse_mode' => null]);
            $messageId = $this->responses->messageIdFromResponse($response, 'Balance adjustment');
            $this->ledger->finalizeSent((string) $claimed->id, $claimed->claim_token, $messageId);
        } catch (Throwable $error) {
            if ($this->ledger->isAmbiguous($error)) {
                $this->ledger->finalizeUnknown((string) $claimed->id, $claimed->claim_token, $error);
            } else {
                $this->ledger->finalizeFailed((string) $claimed->id, $claimed->claim_token, $error, $this->ledger->retryAfterSeconds($error));
            }
        }
    }
}
