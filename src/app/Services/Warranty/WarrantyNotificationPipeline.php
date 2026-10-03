<?php

namespace App\Services\Warranty;

use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramOutboundEffect;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use InvalidArgumentException;
use Throwable;

/** Non-secret status notices reuse the sole Telegram outbound ledger. */
final class WarrantyNotificationPipeline
{
    public function __construct(private readonly TelegramOutboundEffectLedgerProtocol $ledger, private readonly TelegramBotClient $bot, private readonly TelegramLocale $locale, private readonly TelegramOutboundResponseValidator $responses) {}

    public function record(WarrantyClaim $claim): void
    {
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyNotification($claim);
        if ($effect !== null) {
            app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
        }
    }

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::WarrantyNotification->value || $effect->source_type !== WarrantyClaim::class) {
            throw new InvalidArgumentException('Unsupported warranty notification.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $claim = WarrantyClaim::with(['order.customer', 'unit'])->find($claimed->source_id);
            $customer = $claim?->order?->customer;
            $currentKey = $claim === null || $customer === null ? null : 'warranty:'.$claim->id.':'.$claim->state.':'.$claim->settlement_state.':customer:'.$customer->id;
            if ($claim === null || $claimed->effect_group_key !== $currentKey
                || (string) $customer?->telegram_id !== (string) $claimed->telegram_chat_id) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'warranty_notification_unavailable');

                return;
            }
            $key = match ($claim->settlement_state) {
                'refunded' => 'refunded', 'replaced' => 'replaced',
                'refund_pending', 'refund_uncertain' => 'refund_pending',
                'replacement_pending', 'replacement_sending', 'replacement_unknown' => 'replacement_pending',
                'replacement_failed' => 'replacement_failed', 'replacement_review' => 'review',
                default => match ($claim->state) {
                    'approved' => 'choice', 'rejected' => 'rejected', 'expired' => 'expired',
                    'reversed' => 'reversed', 'cancelled' => 'cancelled', 'review' => 'review', default => 'pending',
                },
            };
            $locale = $this->locale->codeForLocale($claimed->locale);
            $text = $this->locale->translateLocale($locale, 'order.warranty.title')."\n"
                .$claim->order->invoice_number.' · #'.$claim->unit->unit_number."\n\n"
                .$this->locale->translateLocale($locale, 'order.warranty.'.$key);
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $text, ['inline_keyboard' => [[[
                'text' => $this->locale->translateLocale($locale, 'order.warranty.title'),
                'url' => app(WarrantyOrderAccess::class)->telegramUrl($claim->order, $locale),
            ]]]]);
            $messageId = $this->responses->messageIdFromResponse($response, 'Warranty status');
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
