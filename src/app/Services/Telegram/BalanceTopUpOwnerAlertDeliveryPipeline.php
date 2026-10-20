<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Support\Money;
use Throwable;

/**
 * Admin-facing delivery for settled top-ups and unresolved money alerts.
 * The top-up lifecycle and the shared ledger protocol stay authoritative.
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
                ->whereIn('effect_type', [
                    TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_OWNER_ALERT,
                    TelegramOutboundEffectType::BalanceTopUpPaidNotification->value,
                ])
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

            $paidNotification = $claimed->effect_type === TelegramOutboundEffectType::BalanceTopUpPaidNotification->value;
            $eligible = $paidNotification
                ? $topUp->status === StoreBalanceTopUpStatus::Paid && $topUp->settled_at !== null
                : $topUp->isEligibleForOwnerAttention();
            if (! $eligible) {
                $this->ledger->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'balance_top_up_owner_alert_not_eligible',
                );

                return;
            }

            $response = $this->bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $paidNotification ? $this->paidMessage($topUp) : $this->message($topUp),
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Store Balance owner alert');

            $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                [
                    'delivery_context' => $paidNotification ? 'balance_top_up_paid_notification' : 'balance_top_up_owner_alert',
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

    private function paidMessage(StoreBalanceTopUp $topUp): string
    {
        $customer = $topUp->customer;
        $username = trim((string) $customer?->username);
        $buyer = $username !== ''
            ? '@'.ltrim($username, '@')
            : (trim((string) $customer?->first_name) ?: 'Customer');
        if (filled($customer?->telegram_id)) {
            $buyer .= ' ('.$customer->telegram_id.')';
        }

        return implode("\n", [
            $this->uiIcons->renderLegacyIcons('🔔 Hasil topup balance'),
            '━━━━━━━━━━━━━━',
            'Invoice: '.$topUp->merchant_ref,
            'Pembeli: '.$buyer,
            'Sumber: Telegram Bot',
            'Status pembayaran: Paid',
            'Saldo dikreditkan: '.Money::rupiah($topUp->amount),
            ...($topUp->provider_total_payment !== null
                ? ['Total pembayaran: '.Money::rupiah($topUp->provider_total_payment)]
                : []),
            'Metode: '.strtoupper((string) ($topUp->provider_payment_method ?: $topUp->provider)),
            'Provider: '.strtoupper((string) $topUp->provider),
            '',
            'Paid at: '.($topUp->paid_at ?? $topUp->settled_at)
                ->copy()->timezone((string) config('app.timezone'))->format('d M Y, H:i'),
            '',
            'Topup selesai. Saldo sudah dikreditkan. Tidak perlu proses ulang.',
        ]);
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
