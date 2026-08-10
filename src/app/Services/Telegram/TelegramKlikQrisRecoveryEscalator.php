<?php

namespace App\Services\Telegram;

use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisOwnerAlert;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Owner-facing adapter for actionable KlikQRIS Native QRIS outcomes.
 *
 * Formats and sends the safe alert selected by KlikQrisNativeQrisService.
 * RecoveryPending is selected only after the configured retry budget is
 * exhausted; InvalidProviderResult is selected immediately. The lifecycle
 * module owns transition state, retry accounting, locking, and deduplication.
 *
 * The adapter only reports. It never switches the active provider and never
 * creates a Manual fallback Payment; Payment Provider Switch stays an owner
 * decision.
 */
class TelegramKlikQrisRecoveryEscalator
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private KlikQrisNativeQrisService $nativeQris,
    ) {}

    /**
     * Observe the current lifecycle state and send an alert when the module
     * says this transition is actionable. Alerting failures never affect the
     * customer-facing payment flow.
     */
    public function notifyFor(Payment $payment): void
    {
        try {
            $alert = $this->nativeQris->ownerAlert($payment);
            if ($alert !== null) {
                $this->notify($payment, $alert);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function notify(Payment $payment, KlikQrisOwnerAlert $alert): void
    {
        $adminChatId = $this->adminAccess->adminChatId();
        if ($adminChatId === null) {
            Log::warning('KlikQRIS owner alert has no admin chat configured.', [
                'payment_id' => $payment->id,
                'outcome' => $alert->outcome()->value,
            ]);

            return;
        }

        try {
            $this->nativeQris->deliverOwnerAlert($payment, $alert, function () use ($adminChatId, $payment, $alert): void {
                $response = $this->bot->sendMessage($adminChatId, $this->message($payment, $alert));
                if (($response['ok'] ?? false) !== true) {
                    throw new RuntimeException('Telegram rejected the KlikQRIS owner alert.');
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function message(Payment $payment, KlikQrisOwnerAlert $alert): string
    {
        $payment->loadMissing('order');
        $outcome = $alert->outcome();

        $lines = [
            '⚠️ KlikQRIS butuh keputusan owner',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.($payment->order?->invoice_number ?? '-'),
            '💰 Tagihan: '.Money::rupiah($payment->amount),
            '📌 Kondisi: '.$this->outcomeLabel($outcome),
        ];

        if ($outcome === KlikQrisNativeQrisOutcome::RecoveryPending) {
            $lines[] = '🔁 Percobaan pemulihan: '.$alert->observations().'x, batas '.$alert->retryBudget().'x';
        }

        $lines[] = '';
        $lines[] = 'Cek dashboard KlikQRIS lalu putuskan sendiri apakah Payment Provider Switch perlu diubah. Sistem tidak mengganti provider dan tidak membuat pembayaran manual otomatis.';

        return implode("\n", $lines);
    }

    private function outcomeLabel(KlikQrisNativeQrisOutcome $outcome): string
    {
        return match ($outcome) {
            KlikQrisNativeQrisOutcome::RecoveryPending => 'Status QR belum bisa dipastikan setelah pemulihan berulang.',
            KlikQrisNativeQrisOutcome::InvalidProviderResult => 'Data QR dari provider tidak valid untuk dibayar.',
        };
    }
}
