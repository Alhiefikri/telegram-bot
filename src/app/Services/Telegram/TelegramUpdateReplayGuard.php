<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;

class TelegramUpdateReplayGuard
{
    private const CHAT_LOCK_RETRY_DELAY_SECONDS = 5;

    /**
     * Claim an update for processing, run the handler once, then mark it done.
     * The first processed/claimed checks are cheap replay fast-paths. The
     * durable claim is a single conditional database update before any Telegram
     * I/O: a worker death after Telegram accepts an outbound command must never
     * replay it, and parallel workers must not both enter the handler.
     */
    public function processOnce(TelegramUpdate $update, callable $handler): void
    {
        if ($update->processed_at || $update->isTerminallyFailed() || $update->hasActiveClaim()) {
            return;
        }

        if (! $this->claim($update)) {
            return;
        }

        $update->refresh();

        try {
            $handler($update);
        } catch (TelegramChatInteractionLockUnavailable $exception) {
            $update->update([
                'processing_started_at' => null,
                'next_retry_at' => now()->addSeconds(self::CHAT_LOCK_RETRY_DELAY_SECONDS),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $attempts = $update->processing_attempts + 1;
            $terminal = $attempts >= max(1, (int) config('services.telegram.processing_max_attempts', 3));
            $retryDelay = max(1, (int) config('services.telegram.processing_retry_delay_seconds', 60));

            $update->update([
                'processing_started_at' => null,
                'processing_attempts' => $attempts,
                'next_retry_at' => $terminal ? null : now()->addSeconds($retryDelay * (2 ** ($attempts - 1))),
                'terminal_failed_at' => $terminal ? now() : null,
                'last_error_class' => class_basename($exception),
                'last_failed_at' => now(),
            ]);

            if ($terminal) {
                report($exception);

                return;
            }

            throw $exception;
        }

        $update->update([
            'processed_at' => now(),
            'processing_started_at' => null,
            'next_retry_at' => null,
            'terminal_failed_at' => null,
            'last_error_class' => null,
        ]);
    }

    private function claim(TelegramUpdate $update): bool
    {
        $leaseCutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));

        return TelegramUpdate::query()
            ->whereKey($update->getKey())
            ->whereNull('processed_at')
            ->whereNull('terminal_failed_at')
            ->where(function ($query) use ($leaseCutoff): void {
                $query->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', $leaseCutoff);
            })
            ->update(['processing_started_at' => now()]) === 1;
    }
}
