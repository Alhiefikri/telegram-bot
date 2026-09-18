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
    private const READINESS_STATE_KEY = 'bsc.owner-alert.readiness.active';

    private const READINESS_LOCK_KEY = 'bsc.owner-alert.readiness.lock';

    private const READINESS_LOCK_SECONDS = 30;

    private const VERIFICATION_STATE_PREFIX = 'bsc.owner-alert.verification-state.';

    private const VERIFICATION_LOCK_SECONDS = 30;

    public function __construct(
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramBotClient $bot,
    ) {}

    public function notifyReadiness(BscReadinessReport $report): void
    {
        if (! $report->enabled || $report->isReady()) {
            $this->rememberReadinessIncident(null);

            return;
        }

        $chatId = $this->adminAccess->adminChatId();
        if ($chatId === null) {
            Log::warning('BSC owner alert has no Telegram target.', [
                'alert_key' => 'readiness',
            ]);

            return;
        }

        $problems = [...$report->missing, ...array_keys($report->invalid)];
        $fingerprint = hash('sha256', implode('|', $problems));
        if (! $this->rememberReadinessIncident($fingerprint)) {
            return;
        }

        $this->sendToChat(
            $chatId,
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
        $actionable = in_array($result->outcome, [
            BscVerificationOutcome::ProviderError,
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed,
            BscVerificationOutcome::ManualReview,
        ], true);
        $stateKey = self::VERIFICATION_STATE_PREFIX.$payment->id;

        if (! $actionable) {
            $this->clearVerificationIncident($stateKey);

            return;
        }

        $payment->loadMissing(['order', 'bscDetail']);
        $submissionVersion = (int) ($payment->bscDetail?->tx_submission_version ?? 0);
        $hash = strtolower(trim((string) ($payment->bscDetail?->current_tx_hash ?? '')));
        $fingerprint = hash('sha256', implode('|', [
            $result->outcome->value,
            (string) $submissionVersion,
            $hash,
        ]));
        $lock = Cache::lock($stateKey.':lock', self::VERIFICATION_LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }

        try {
            $active = Cache::get($stateKey);
            if (is_string($active) && hash_equals($active, $fingerprint)) {
                return;
            }

            Cache::forever($stateKey, $fingerprint);
            $chatId = $this->adminAccess->adminChatId();
            if ($chatId === null) {
                Log::warning('BSC owner alert has no Telegram target.', [
                    'alert_key' => 'verification:'.$payment->id,
                ]);

                return;
            }

            $invoice = (string) ($payment->order?->invoice_number ?? $payment->id);
            $this->sendToChat(
                $chatId,
                "⚠️ BSC payment perlu perhatian\n━━━━━━━━━━━━━━\nInvoice: {$invoice}\nOutcome: {$result->outcome->value}\nPayment ID: ".hash('sha256', (string) $payment->id),
            );
        } finally {
            $lock->release();
        }
    }

    private function clearVerificationIncident(string $stateKey): void
    {
        $lock = Cache::lock($stateKey.':lock', self::VERIFICATION_LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }

        try {
            Cache::forget($stateKey);
        } finally {
            $lock->release();
        }
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

        $this->sendToChat($chatId, $message);
    }

    private function sendToChat(string $chatId, string $message): void
    {
        try {
            $this->bot->sendMessage($chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function rememberReadinessIncident(?string $fingerprint): bool
    {
        $lock = Cache::lock(self::READINESS_LOCK_KEY, self::READINESS_LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }

        try {
            if ($fingerprint === null) {
                Cache::forget(self::READINESS_STATE_KEY);

                return false;
            }

            $active = Cache::get(self::READINESS_STATE_KEY);
            if (is_string($active) && hash_equals($active, $fingerprint)) {
                return false;
            }

            Cache::forever(self::READINESS_STATE_KEY, $fingerprint);

            return true;
        } finally {
            $lock->release();
        }
    }
}
