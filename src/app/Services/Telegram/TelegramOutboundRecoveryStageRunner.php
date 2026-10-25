<?php

namespace App\Services\Telegram;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one bounded recovery stage without letting it abort the remaining
 * sweep. A failed stage is reported to the caller and logged with sanitized
 * evidence; the sweep continues so one slow query cannot starve every other
 * recovery facet.
 */
final class TelegramOutboundRecoveryStageRunner
{
    /** @var array<string, class-string<Throwable>> */
    private array $failures = [];

    public function run(string $stage, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->failures[$stage] = $exception::class;

            Log::warning('telegram.outbound_recovery_stage_failed', [
                'stage' => $stage,
                'exception' => $exception::class,
                'sqlstate' => $exception instanceof QueryException
                    ? (string) (($exception->errorInfo[0] ?? null) ?: $exception->getCode())
                    : null,
            ]);

            return null;
        }
    }

    /**
     * @return array<string, class-string<Throwable>>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }
}
