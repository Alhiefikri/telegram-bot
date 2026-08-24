<?php

namespace App\Services\Bsc;

use App\Models\Payment;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BscOperationalAlertNotifier
{
    public function __construct(
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramBotClient $bot,
    ) {}

    public function notifyReadiness(BscReadinessReport $report): void
    {
        if (! $report->enabled || $report->isReady()) {
            return;
        }

        $problems = [...$report->missing, ...array_keys($report->invalid)];
        $this->sendOnce(
            'readiness:'.hash('sha256', implode('|', $problems)),
            "🚨 BSC readiness gagal\n━━━━━━━━━━━━━━\nMasalah: ".implode(', ', $problems)."\nReceiver aktif: {$report->activeReceiverCount}",
        );
    }

    public function notifyBacklog(int $pending, int $threshold, int $oldestAgeSeconds): void
    {
        if ($pending <= $threshold) {
            return;
        }

        $this->sendOnce(
            'backlog',
            "🚨 BSC verification backlog\n━━━━━━━━━━━━━━\nPayment pending: {$pending}\nBatas alert: {$threshold}\nPayment tertua: {$oldestAgeSeconds}s",
        );
    }

    public function notifyVerification(Payment $payment, BscVerificationResult $result): void
    {
        if (! in_array($result->outcome, [
            BscVerificationOutcome::ProviderError,
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed,
            BscVerificationOutcome::ManualReview,
        ], true)) {
            return;
        }

        $payment->loadMissing('order');
        $invoice = (string) ($payment->order?->invoice_number ?? $payment->id);
        $this->sendOnce(
            'verification:'.$payment->id.':'.$result->outcome->value,
            "⚠️ BSC payment perlu perhatian\n━━━━━━━━━━━━━━\nInvoice: {$invoice}\nOutcome: {$result->outcome->value}\nPayment ID: ".hash('sha256', (string) $payment->id),
        );
    }

    private function sendOnce(string $key, string $message): void
    {
        $cooldown = max(60, (int) config('services.bsc.owner_alert_cooldown_seconds', 900));
        if (! Cache::add('bsc.owner-alert.'.$key, true, now()->addSeconds($cooldown))) {
            return;
        }

        $chatId = $this->adminAccess->adminChatId();
        if ($chatId === null) {
            Log::warning('BSC owner alert has no Telegram target.', ['alert_key' => $key]);

            return;
        }

        try {
            $this->bot->sendMessage($chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
