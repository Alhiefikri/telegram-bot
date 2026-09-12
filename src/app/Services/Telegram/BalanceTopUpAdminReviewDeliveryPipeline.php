<?php

namespace App\Services\Telegram;

use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPresentation;
use Throwable;

/**
 * Admin review notification for a manual Store Balance top-up.
 *
 * Delivery stays inside the shared Telegram Outbound Effect ledger: this
 * pipeline only renders and sends the claimed effect.
 */
final class BalanceTopUpAdminReviewDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly StoreBalanceTopUpService $topUps,
        private readonly TelegramLocale $locale,
        private readonly TelegramHtmlCaption $html,
    ) {}

    public function deliver(string $topUpId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? TelegramOutboundEffect::query()
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_MANUAL_REVIEW)
                ->first()
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_MANUAL_REVIEW)
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

            $presentation = $this->topUps->presentation($topUp);
            if (! $presentation->isAwaitingConfirmation()) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_not_awaiting_confirmation',
                );

                return;
            }

            $locale = TelegramLocale::DEFAULT_LOCALE;
            $message = $this->html->html(implode("\n", $this->lines($topUp, $presentation, $locale)), 4096);
            $replyMarkup = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => $this->locale->translateLocale($locale, 'balance.topup.admin_review.approve'),
                            'callback_data' => 'admin:topup-approve:'.$topUp->getKey(),
                        ],
                        [
                            'text' => $this->locale->translateLocale($locale, 'balance.topup.admin_review.reject'),
                            'callback_data' => 'admin:topup-reject:'.$topUp->getKey(),
                        ],
                    ],
                ],
            ];

            $response = $this->bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $message,
                $replyMarkup,
                ['parse_mode' => 'HTML'],
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance top-up admin review');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_top_up_admin_review',
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

    /** @return list<string> */
    private function lines(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPresentation $presentation,
        string $locale,
    ): array {
        $payable = $presentation->payable();
        $lines = [
            $this->locale->translateLocale($locale, 'balance.topup.admin_review.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translateLocale($locale, 'balance.topup.admin_review.credit', [
                'amount' => $this->locale->idr((int) $topUp->amount),
            ]),
        ];

        if ($payable !== null) {
            $lines[] = $this->locale->translateLocale($locale, 'balance.topup.admin_review.total', [
                'amount' => $this->locale->idr($payable->totalAmount()),
            ]);
            $lines[] = $this->locale->translateLocale($locale, 'balance.topup.admin_review.deadline', [
                'expires' => $this->locale->formatDate($topUp->customer, $payable->effectiveExpiresAt()),
            ]);
        }

        $customer = $topUp->customer;
        if ($customer !== null) {
            $lines[] = $this->locale->translateLocale($locale, 'balance.topup.admin_review.customer', [
                'reference' => '#'.(int) $topUp->getKey(),
            ]);
        }

        $lines[] = $this->locale->translateLocale($locale, 'balance.topup.admin_review.help');

        return $lines;
    }
}
