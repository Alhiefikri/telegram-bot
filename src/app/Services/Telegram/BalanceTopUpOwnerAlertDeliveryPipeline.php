<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Support\Money;
use Throwable;

/**
 * Admin-facing alert for Store Balance top-ups that may carry real money but
 * could not be settled automatically. Delivery is claim/finalize work only;
 * the top-up lifecycle and the ledger protocol stay authoritative.
 */
final class BalanceTopUpOwnerAlertDeliveryPipeline
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'recovery_pending' => 'Recovery pending (status provider belum dapat dipastikan)',
        'invalid_provider_result' => 'Hasil provider invalid / butuh peninjauan',
        'expired' => 'Kedaluwarsa dengan bukti pembayaran',
    ];

    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramBotClient $bot,
        private readonly TelegramUiIcons $uiIcons,
    ) {}

    public function deliver(string $topUpId, ?string $effectId = null): void
    {
        $effect = $effectId === null
            ? $this->outboundEffects->forBalanceTopUpOwnerAlert($topUpId)
            : TelegramOutboundEffect::query()
                ->whereKey($effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_OWNER_ALERT)
                ->firstOrFail();

        if ($effect === null) {
            return;
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            if (blank(config('services.telegram.token'))
                || preg_match('/^[1-9][0-9]*$/', trim((string) $claimed->telegram_chat_id)) !== 1) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'admin_target_missing',
                );

                return;
            }

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

            if (! $topUp->isEligibleForOwnerAttention()) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_owner_alert_not_eligible',
                );

                return;
            }

            $response = $this->bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $this->message($topUp),
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance owner alert');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => 'balance_top_up_owner_alert',
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

    private function message(StoreBalanceTopUp $topUp): string
    {
        $status = $topUp->status?->value ?? (string) $topUp->getRawOriginal('status');
        $lines = [
            $this->uiIcons->renderLegacyIcons('⚠️ Top-up saldo butuh keputusan owner'),
            '━━━━━━━━━━━━━━',
            $this->uiIcons->renderLegacyIcons('🧾 Top-up: ').'#'.$topUp->getKey(),
            $this->uiIcons->renderLegacyIcons('👤 Customer: ').$this->customerLabel($topUp->customer),
            $this->uiIcons->renderLegacyIcons('💰 Nominal: ').Money::idr($topUp->amount),
            $this->uiIcons->renderLegacyIcons('📌 Kondisi: ').(self::STATUS_LABELS[$status] ?? $status),
        ];

        $detail = trim((string) $topUp->provider_create_last_error);
        if ($detail !== '') {
            $lines[] = $this->uiIcons->renderLegacyIcons('🔎 Detail: ').mb_strimwidth(
                TelegramBotApiRedactor::redactText($detail),
                0,
                240,
                '...',
                'UTF-8',
            );
        }

        $lines[] = 'Buka dashboard admin pada tab Kompensasi Top-up: periksa provider sebelum memutuskan release saldo.';

        return implode("\n", $lines);
    }

    private function customerLabel(?Customer $customer): string
    {
        if ($customer === null) {
            return 'Customer tidak ditemukan';
        }

        $name = trim((string) ($customer->first_name ?: $customer->username ?: $customer->telegram_id ?: 'Customer'));
        $username = trim((string) $customer->username);

        return $username === '' ? $name : $name.' (@'.ltrim($username, '@').')';
    }
}
