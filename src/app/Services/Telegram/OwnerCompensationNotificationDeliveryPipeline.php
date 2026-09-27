<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceCompensation;
use App\Models\TelegramOutboundEffect;
use Throwable;

final class OwnerCompensationNotificationDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramHtmlCaption $html,
        private readonly TelegramUiIcons $uiIcons,
    ) {}

    public function deliver(string $compensationId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? $this->outboundEffects->forOwnerCompensation($compensationId)
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceCompensation::class)
                ->where('source_id', $compensationId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_OWNER_COMPENSATION_NOTIFICATION)
                ->firstOrFail();

        if ($effect === null) {
            return;
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $compensation = StoreBalanceCompensation::query()
                ->with(['customer', 'topUp'])
                ->whereKey($claimed->source_id)
                ->first();
            if ($compensation === null) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_owner_compensation_missing',
                );

                return;
            }

            $topUp = $compensation->topUp;
            $customer = $compensation->customer;
            $ledgerEntry = $compensation->ledgerEntry;
            if ($topUp === null
                || $customer === null
                || $ledgerEntry === null
                || $ledgerEntry->kind !== StoreBalanceEntryKind::OwnerCompensationCredit
                || $ledgerEntry->amount !== (int) $topUp->amount
                || $ledgerEntry->source_type !== 'owner_compensation'
                || $topUp->status !== StoreBalanceTopUpStatus::Compensated
                || (int) $topUp->customer_id !== (int) $customer->getKey()
                || (int) $compensation->customer_id !== (int) $customer->getKey()
                || (int) $compensation->amount !== (int) $topUp->amount
                || $customer->telegram_id === null
                || trim((string) $topUp->chat_id) !== (string) $customer->telegram_id
                || trim((string) $claimed->telegram_chat_id) !== trim((string) $topUp->chat_id)) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_owner_compensation_not_eligible',
                );

                return;
            }

            $locale = (string) ($claimed->locale ?: $customer->locale ?: TelegramLocale::DEFAULT_LOCALE);
            $message = implode("\n", [
                $this->locale->translateLocale($locale, 'balance.compensation.title'),
                $this->locale->translateLocale($locale, 'balance.compensation.credit', [
                    'amount' => $this->locale->idr((int) $compensation->amount),
                ]),
                $this->locale->translateLocale($locale, 'balance.compensation.explanation'),
                $this->locale->translateLocale($locale, 'balance.compensation.reference', [
                    'reference' => '#'.$topUp->getKey(),
                ]),
                $this->locale->translateLocale($locale, 'balance.compensation.resolved_at', [
                    'date' => $this->locale->formatDate($customer, $compensation->resolved_at ?? $compensation->created_at),
                ]),
                $this->locale->translateLocale($locale, 'balance.compensation.support'),
            ]);
            $message = $this->html->html($message, 4096);
            $replyMarkup = [
                'inline_keyboard' => [[
                    [
                        ...$this->uiIcons->apply('menu.balance', $this->locale->translateLocale($locale, 'menu.balance')),
                        'callback_data' => 'balance',
                    ],
                    [
                        ...$this->uiIcons->apply('menu.home', $this->locale->translateLocale($locale, 'menu.home')),
                        'callback_data' => 'menu:home',
                    ],
                ], [
                    [
                        ...$this->uiIcons->apply('menu.support', $this->locale->translateLocale($locale, 'menu.support')),
                        'callback_data' => 'menu:support',
                    ],
                ]],
            ];

            $response = $this->bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $message,
                $replyMarkup,
                ['parse_mode' => 'HTML'],
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance compensation notification');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_owner_compensation',
                    'delivery_method' => 'sendMessage',
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
