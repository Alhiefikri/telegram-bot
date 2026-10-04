<?php

namespace App\Services\Suppliers;

use App\Enums\TelegramOutboundEffectType;
use App\Models\SupplierHealthEvent;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use InvalidArgumentException;
use Throwable;

final class SupplierHealthDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responses,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::SupplierHealth->value || $effect->source_type !== SupplierHealthEvent::class) {
            throw new InvalidArgumentException('Unsupported supplier health effect.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $event = SupplierHealthEvent::find($claimed->source_id);
            if ($event === null || SupplierHealthService::adminTarget() !== (string) $claimed->telegram_chat_id) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'admin_target_missing');

                return;
            }
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $event->message, options: ['parse_mode' => 'HTML']);
            $messageId = $this->responses->messageIdFromResponse($response, 'Supplier health');
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
