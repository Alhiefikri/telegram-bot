<?php

namespace App\Services\Suppliers;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\SupplierFulfillment;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use InvalidArgumentException;
use Throwable;

final class SupplierManualTakeoverNotice
{
    public function __construct(
        private readonly TelegramLocale $locale,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responses,
    ) {}

    public function record(SupplierFulfillment $fulfillment, Order $order): TelegramOutboundEffect
    {
        $customer = $order->customer;
        $chatId = (string) $customer?->telegram_id;
        $deliverable = preg_match('/^[1-9][0-9]{0,18}$/D', $chatId) === 1;
        $key = 'supplier-manual-takeover:'.$fulfillment->id.':v1';

        return TelegramOutboundEffect::firstOrCreate(['effect_key' => $key], [
            'effect_group_key' => $key, 'sequence' => 1,
            'effect_type' => TelegramOutboundEffectType::SupplierManualTakeoverNotice->value,
            'source_type' => SupplierFulfillment::class, 'source_id' => (string) $fulfillment->id,
            'target_type' => 'customer', 'telegram_chat_id' => $chatId,
            'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE, 'telegram_method' => 'sendMessage',
            'locale' => $this->locale->code($customer), 'template_version' => 'supplier-manual-takeover-v1',
            'state' => $deliverable ? TelegramOutboundEffectState::Pending->value : TelegramOutboundEffectState::ManualReview->value,
            'attempts' => 0, 'next_attempt_at' => $deliverable ? now() : null,
            'manual_review_at' => $deliverable ? null : now(),
            'last_error_code' => $deliverable ? null : 'customer_telegram_target_missing',
            'outcome_metadata' => [
                'delivery_context' => 'supplier_manual_takeover',
                'message' => $this->locale->translate($customer, 'supplier.manual_takeover.notice', ['invoice' => $order->invoice_number]),
                'contact_label' => $this->locale->translate($customer, 'supplier.manual_takeover.contact'),
            ],
        ]);
    }

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::SupplierManualTakeoverNotice->value || $effect->source_type !== SupplierFulfillment::class) {
            throw new InvalidArgumentException('Unsupported supplier takeover notice.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $message = $claimed->outcome_metadata['message'] ?? null;
            if (! is_string($message) || $message === '') {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'supplier_takeover_notice_missing');

                return;
            }
            $label = $claimed->outcome_metadata['contact_label'] ?? '💬 Contact Admin';
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $message, [
                'inline_keyboard' => [[['text' => $label, 'url' => 'https://t.me/Alhie02', 'style' => 'primary']]],
            ], options: ['parse_mode' => null]);
            $messageId = $this->responses->messageIdFromResponse($response, 'Supplier manual takeover');
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
