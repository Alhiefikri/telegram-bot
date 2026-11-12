<?php

namespace App\Services\Telegram;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotates bounded recovery stages; the cursor is a fairness hint, never
 * delivery authority. A hard-killed stage cannot monopolize the next sweep.
 */
final class TelegramOutboundRecoveryStageRunner
{
    private const CURSOR_KEY = 'telegram:recover-outbound:next-stage';

    private readonly Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /** @var array<string, class-string<Throwable>> */
    private array $failures = [];

    /**
     * @param  array<string, callable(): mixed>  $operations
     * @return array<string, mixed>
     */
    public function run(array $operations, float $maxSeconds): array
    {
        $this->failures = [];
        $names = array_keys($operations);
        if ($names === []) {
            return [];
        }

        $start = array_search(Cache::get(self::CURSOR_KEY), $names, true);
        $start = $start === false ? 0 : $start;
        $deadline = ($this->clock)() + $maxSeconds;
        $results = [];
        $count = count($names);

        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($start + $offset) % $count;
            $stage = $names[$index];
            if (($this->clock)() >= $deadline) {
                Cache::put(self::CURSOR_KEY, $stage, 86_400);
                Log::info('telegram.outbound_recovery_deferred', [
                    'next_stage' => $stage,
                    'remaining_stages' => $count - $offset,
                ]);

                return $results;
            }

            // Persist before execution so even SIGALRM/process death rotates.
            Cache::put(self::CURSOR_KEY, $names[($index + 1) % $count], 86_400);
            try {
                $results[$stage] = $operations[$stage]();
            } catch (Throwable $exception) {
                $this->failures[$stage] = $exception::class;
                Log::warning('telegram.outbound_recovery_stage_failed', [
                    'stage' => $stage,
                    'exception' => $exception::class,
                    'sqlstate' => $exception instanceof QueryException
                        ? (string) (($exception->errorInfo[0] ?? null) ?: $exception->getCode())
                        : null,
                ]);
            }
        }

        Cache::forget(self::CURSOR_KEY);

        return $results;
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
