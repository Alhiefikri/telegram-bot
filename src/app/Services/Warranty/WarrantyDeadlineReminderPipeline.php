<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\SupplierAccountUnit;
use App\Models\TelegramOutboundEffect;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use InvalidArgumentException;
use Throwable;

/** Deadline reminders reuse the sole Telegram outbound ledger and its ambiguity policy. */
final class WarrantyDeadlineReminderPipeline
{
    public function __construct(private readonly TelegramOutboundEffectLedgerProtocol $ledger, private readonly TelegramBotClient $bot, private readonly TelegramLocale $locale, private readonly TelegramOutboundResponseValidator $responses) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::WarrantyDeadlineReminder->value || $effect->source_type !== SupplierAccountUnit::class) {
            throw new InvalidArgumentException('Unsupported warranty deadline reminder.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $unit = SupplierAccountUnit::with(['order.customer', 'stockItem'])->find($claimed->source_id);
            $customer = $unit?->order?->customer;
            $currentKey = $unit === null || $customer === null ? null : 'warranty-reminder:'.$unit->id.':customer:'.$customer->id;
            if ($unit === null || $claimed->effect_group_key !== $currentKey
                || (string) $customer?->telegram_id !== (string) $claimed->telegram_chat_id) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'warranty_reminder_unavailable');

                return;
            }
            if (! $unit->refund_deadline->isFuture()) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'warranty_reminder_expired');

                return;
            }
            if ($unit->provider_state !== 'active' || $unit->confirmation_state !== null
                || $unit->stockItem?->retired_at !== null
                || $unit->order->payment_status !== PaymentStatus::Paid
                || ! in_array($unit->order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                || $unit->order->hasOpenLatePaymentReview()
                || WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->exists()) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'warranty_reminder_no_longer_eligible');

                return;
            }
            $locale = $this->locale->codeForLocale($claimed->locale);
            $deadline = $this->locale->formatDate($customer, $unit->refund_deadline);
            $text = $this->locale->translateLocale($locale, 'order.warranty.title')."\n"
                .$unit->order->invoice_number.' · #'.$unit->unit_number."\n\n"
                .$this->locale->translateLocale($locale, 'order.warranty.deadline_reminder', ['deadline' => $deadline]);
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $text, ['inline_keyboard' => [[[
                'text' => $this->locale->translateLocale($locale, 'order.warranty.submit'),
                'url' => app(WarrantyOrderAccess::class)->telegramUrl($unit->order, $locale),
            ]]]]);
            $messageId = $this->responses->messageIdFromResponse($response, 'Warranty deadline reminder');
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
