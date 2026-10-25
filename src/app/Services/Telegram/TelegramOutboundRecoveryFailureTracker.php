<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;

/**
 * Counts consecutive failed recovery sweeps so a single transient database
 * stall does not open a critical runtime episode. The counter is a local
 * supervision hint only: it never authorizes or suppresses Telegram delivery.
 */
final class TelegramOutboundRecoveryFailureTracker
{
    public const ALERT_THRESHOLD = 2;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('framework/telegram-outbound-recovery-failures');
    }

    public function recordFailure(): int
    {
        $failures = $this->failures() + 1;

        File::put($this->path, (string) $failures);

        return $failures;
    }

    public function recordSuccess(): void
    {
        File::delete($this->path);
    }

    public function failures(): int
    {
        if (! is_file($this->path)) {
            return 0;
        }

        $value = trim((string) File::get($this->path));

        return ctype_digit($value) ? (int) $value : 0;
    }
}
