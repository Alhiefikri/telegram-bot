<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\Cache;

class TelegramUpdateReplayGuard
{
    /**
     * Claim an update for processing, run the handler once, then mark it done.
     * The first processed/claimed checks are cheap replay fast-paths. The
     * second checks happen after the cache lock is acquired and the model is
     * refreshed. The durable claim is written before any Telegram I/O: a worker
     * death after Telegram accepts an outbound command must never replay it.
     */
    public function processOnce(TelegramUpdate $update, callable $handler): void
    {
        if ($update->processed_at || $update->isTerminallyFailed() || $update->hasActiveClaim()) {
            return;
        }

        $lock = Cache::lock($this->lockKey($update), 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $update->refresh();
            if ($update->processed_at || $update->isTerminallyFailed() || $update->hasActiveClaim()) {
                return;
            }

            $update->update(['processing_started_at' => now()]);

            try {
                $handler($update);
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
        } finally {
            $lock->release();
        }
    }

    private function lockKey(TelegramUpdate $update): string
    {
        return 'telegram-update:'.$update->id;
    }
}
