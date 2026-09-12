<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Support\PaymentProvider;
use Throwable;

/**
 * Forward one manual Store Balance top-up payment proof to the admin chat.
 *
 * The customer's photo is the review artifact, exactly like a manual product
 * purchase proof: the admin approves or rejects it with inline actions and
 * only the shared confirmation service may credit the balance.
 */
final class BalanceTopUpPaymentProofDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramHtmlCaption $html,
    ) {}

    public function deliver(string $topUpId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? TelegramOutboundEffect::query()
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
                ->first()
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
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

            if ($topUp->status !== StoreBalanceTopUpStatus::AwaitingConfirmation
                || strtolower(trim((string) $topUp->provider)) !== PaymentProvider::MANUAL
                || ! $topUp->hasPaymentProof()) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_not_awaiting_confirmation',
                );

                return;
            }

            $locale = TelegramLocale::DEFAULT_LOCALE;
            $response = $this->bot->sendPhotoReference(
                (string) $claimed->telegram_chat_id,
                (string) $topUp->payment_proof_file_id,
                $this->html->html(implode("\n", $this->lines($topUp, $locale)), 1024),
                $this->replyMarkup($topUp, $locale),
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance top-up payment proof');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_top_up_payment_proof',
                    'delivery_method' => 'sendPhoto',
                    'attachment_type' => 'payment_proof',
                ],
            );

            $this->markForwarded($topUp);
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
    private function lines(StoreBalanceTopUp $topUp, string $locale): array
    {
        $payable = app(StoreBalanceTopUpService::class)->presentation($topUp)->payable();
        $lines = [
            $this->locale->translateLocale($locale, 'balance.topup.payment_proof.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translateLocale($locale, 'balance.topup.payment_proof.credit', [
                'amount' => $this->locale->idr((int) $topUp->amount),
            ]),
        ];

        if ($payable !== null) {
            $lines[] = $this->locale->translateLocale($locale, 'balance.topup.payment_proof.total', [
                'amount' => $this->locale->idr($payable->totalAmount()),
            ]);
            $lines[] = $this->locale->translateLocale($locale, 'balance.topup.payment_proof.deadline', [
                'expires' => $this->locale->formatDate($topUp->customer, $payable->effectiveExpiresAt()),
            ]);
        }

        $lines[] = $this->locale->translateLocale($locale, 'balance.topup.payment_proof.reference', [
            'reference' => '#'.(int) $topUp->getKey(),
        ]);
        $lines[] = $this->locale->translateLocale($locale, 'balance.topup.payment_proof.help');

        return $lines;
    }

    /** @return array<string, mixed> */
    private function replyMarkup(StoreBalanceTopUp $topUp, string $locale): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => $this->locale->translateLocale($locale, 'balance.topup.payment_proof.approve'),
                        'callback_data' => 'admin:topup-approve:'.$topUp->getKey(),
                    ],
                    [
                        'text' => $this->locale->translateLocale($locale, 'balance.topup.payment_proof.reject'),
                        'callback_data' => 'admin:topup-reject:'.$topUp->getKey(),
                    ],
                ],
            ],
        ];
    }

    private function markForwarded(StoreBalanceTopUp $topUp): void
    {
        if ($topUp->payment_proof_forwarded_at !== null) {
            return;
        }

        StoreBalanceTopUp::query()
            ->whereKey($topUp->getKey())
            ->whereNull('payment_proof_forwarded_at')
            ->update(['payment_proof_forwarded_at' => now()]);
    }
}
