<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use Throwable;

final class BalanceTopUpReceiptDeliveryPipeline
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

    public function deliver(string $topUpId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? $this->outboundEffects->forBalanceTopUpReceipt($topUpId)
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
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
                    'balance_top_up_receipt_missing',
                );

                return;
            }

            if ($topUp->status !== StoreBalanceTopUpStatus::Paid
                || $topUp->provider_total_payment === null
                || $topUp->settled_at === null
                || $topUp->customer === null
                || $topUp->customer->telegram_id === null
                || trim((string) $topUp->chat_id) !== (string) $topUp->customer->telegram_id
                || trim((string) $claimed->telegram_chat_id) !== trim((string) $topUp->chat_id)) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_not_paid',
                );

                return;
            }

            $locale = (string) ($claimed->locale ?: $topUp->locale ?: $topUp->customer->locale ?: TelegramLocale::DEFAULT_LOCALE);
            $paidAt = $topUp->paid_at ?? $topUp->settled_at;
            $providerReference = trim((string) ($topUp->provider_reference ?: $topUp->merchant_ref));
            $message = implode("\n", [
                $this->locale->translateLocale($locale, 'balance.topup.receipt.title'),
                $this->locale->translateLocale($locale, 'balance.topup.receipt.credit', [
                    'amount' => $this->locale->idr((int) $topUp->amount),
                ]),
                $this->locale->translateLocale($locale, 'balance.topup.receipt.provider_total', [
                    'amount' => $this->locale->idr((int) $topUp->provider_total_payment),
                ]),
                $this->locale->translateLocale($locale, 'balance.topup.receipt.reference', [
                    'reference' => $providerReference,
                ]),
                $this->locale->translateLocale($locale, 'balance.topup.receipt.paid_at', [
                    'date' => $this->locale->formatDate($topUp->customer, $paidAt),
                ]),
                $this->locale->translateLocale($locale, 'balance.topup.receipt.support'),
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
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance top-up receipt');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_top_up_receipt',
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
