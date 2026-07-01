<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\Cache;

class TelegramUpdateReplayGuard
{
    /**
     * Claim an update for processing, run the handler once, then mark it done.
     *
     * The first processed_at check is a cheap replay fast-path. The second check
     * happens after the cache lock is acquired and the model is refreshed, which
     * preserves the existing duplicate-job behavior for in-flight updates.
     */
    public function processOnce(TelegramUpdate $update, callable $handler): void
    {
        if ($update->processed_at) {
            return;
        }

        $lock = Cache::lock($this->lockKey($update), 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $update->refresh();
            if ($update->processed_at) {
                return;
            }

            $handler($update);

            $update->update(['processed_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function lockKey(TelegramUpdate $update): string
    {
        return 'telegram-update:'.$update->id;
    }
}
