<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileStoreBalanceTopUps extends Command
{
    private const MAX_CHUNK = 1000;

    private const MAX_TOP_UPS = 100;

    private const CURSOR_TTL_SECONDS = 86_400;

    private const TOP_UP_COLUMNS = [
        'id',
        'customer_id',
        'chat_id',
        'locale',
        'provider',
        'merchant_ref',
        'currency',
        'amount',
        'status',
        'provider_reference',
        'provider_payload',
        'provider_qris_url',
        'provider_fee',
        'provider_total_payment',
        'provider_payment_method',
        'provider_expires_at',
        'expires_at',
        'provider_signature',
        'provider_create_state',
        'provider_create_last_error',
        'paid_at',
        'settled_at',
        'created_at',
        'updated_at',
    ];

    protected $signature = 'klikqris:reconcile-balance-top-ups {--chunk=100} {--max-top-ups=10} {--after= : Resume the fair sweep after this created_at timestamp instead of the persisted cursor.}';

    protected $description = 'Reconcile eligible Store Balance top-ups from authoritative KlikQRIS transaction status.';

    public function __construct(
        private readonly ?Closure $deadlineFactory = null,
    ) {
        parent::__construct();
    }

    public function handle(
        StoreBalanceTopUpService $topUps,
        KlikQrisProviderReadiness $readiness,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundDispatcher,
    ): int {
        $chunk = $this->validatedInteger('chunk', self::MAX_CHUNK);
        $maxTopUps = $this->validatedInteger('max-top-ups', self::MAX_TOP_UPS);
        if ($chunk === null || $maxTopUps === null) {
            $this->error('klikqris:reconcile-balance-top-ups failed: chunk must be 1-1000 and max-top-ups must be 1-100.');

            return self::FAILURE;
        }

        $environment = KlikQrisEnvironment::runtime();
        $report = $readiness->forEnvironment($environment);
        if (! $report->isReady()) {
            if ($readiness->disabledForEnvironment($environment)) {
                $this->info('klikqris:reconcile-balance-top-ups skipped safely: KlikQRIS is disabled for this environment.');

                return self::SUCCESS;
            }

            $this->error('klikqris:reconcile-balance-top-ups failed: active KlikQRIS environment is not ready.');

            return self::FAILURE;
        }

        $afterOverride = $this->cursorAfterOverride();
        if ($afterOverride === false) {
            $this->error('klikqris:reconcile-balance-top-ups failed: after must be a valid timestamp.');

            return self::FAILURE;
        }

        $startedAt = hrtime(true);
        $executionDeadline = $this->executionDeadline();
        $cursorKey = $this->cursorKey($environment);
        $cursor = $this->resumeCursor($cursorKey, $afterOverride);
        $wrapped = false;
        $pagesFetched = 0;
        $scanCap = $this->cursorScanCap();
        $eligible = 0;
        $selected = 0;
        $recovered = 0;
        $pending = 0;
        $expired = 0;
        $review = 0;
        $failed = 0;

        do {
            $pagesFetched++;
            $page = StoreBalanceTopUp::query()
                ->select(self::TOP_UP_COLUMNS)
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->whereNotNull('merchant_ref')
                ->whereIn('status', [
                    StoreBalanceTopUpStatus::Pending->value,
                    StoreBalanceTopUpStatus::Payable->value,
                    StoreBalanceTopUpStatus::RecoveryPending->value,
                    StoreBalanceTopUpStatus::Failed->value,
                ])
                ->whereNotNull('provider_create_state')
                ->where('provider_create_state', '!=', 'create_failed')
                ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            $eligible += $page->count();
            $stoppedEarly = false;
            foreach ($page as $topUp) {
                if ($selected >= $maxTopUps || hrtime(true) >= $executionDeadline) {
                    $stoppedEarly = true;

                    break;
                }

                $cursor = $this->cursorFor($topUp);
                $selected++;
                $itemStartedAt = hrtime(true);

                try {
                    if ($topUps->expireIfDue($topUp)) {
                        $topUp->refresh();
                        $expired++;
                        $this->reportRow($topUp, 'expired', $itemStartedAt);

                        continue;
                    }

                    $result = $topUps->reconcile($topUp);
                    $topUp->refresh();

                    $outcome = match (true) {
                        $topUp->status === StoreBalanceTopUpStatus::Paid => 'recovered',
                        $topUp->status === StoreBalanceTopUpStatus::Expired
                            || $result->outcome() === KlikQrisNativeQrisOutcome::Expired => 'expired',
                        $topUp->status === StoreBalanceTopUpStatus::InvalidProviderResult
                            || $topUp->provider_create_state === 'reconciliation_review'
                            || $result->outcome() === KlikQrisNativeQrisOutcome::InvalidProviderResult => 'review',
                        default => 'pending',
                    };

                    if ($outcome === 'pending' && $result->isPayable()) {
                        $effect = $outboundEffects->recordBalanceTopUpInstructions(
                            $topUp->fresh(['customer']),
                            $topUp->customer,
                        );
                        if ($effect !== null) {
                            $outboundDispatcher->dispatch($effect);
                        }
                    }

                    match ($outcome) {
                        'recovered' => $recovered++,
                        'expired' => $expired++,
                        'review' => $review++,
                        default => $pending++,
                    };

                    $this->reportRow($topUp, $outcome, $itemStartedAt);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error('Store Balance top-up reconciliation failed id='.$topUp->getKey().': '.$exception::class);
                    Log::warning('klikqris.balance_top_up_reconciliation.failed', [
                        'top_up_id' => (string) $topUp->getKey(),
                        'merchant_ref_hash' => $this->referenceHash($topUp->merchant_ref),
                        'exception' => $exception::class,
                        'retryable' => $this->isRetryable($exception),
                        'duration_ms' => $this->elapsedMilliseconds($itemStartedAt),
                    ]);
                }
            }

            $reachedEnd = $page->count() < $chunk;
            if ($reachedEnd && ! $stoppedEarly) {
                $wrapped = true;
            }
        } while (! $reachedEnd
            && ! $stoppedEarly
            && $selected < $maxTopUps
            && hrtime(true) < $executionDeadline
            && $pagesFetched < $scanCap);

        if ($wrapped) {
            Cache::forget($cursorKey);
            $cursor = null;
        } elseif ($cursor !== null) {
            Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
        }

        $summary = "selected={$selected} eligible={$eligible} recovered={$recovered} pending={$pending} expired={$expired} review={$review} failed={$failed}";
        Log::info('klikqris.balance_top_up_reconciliation.completed', [
            'environment' => $environment->value,
            'chunk' => $chunk,
            'max_top_ups' => $maxTopUps,
            'eligible' => $eligible,
            'selected' => $selected,
            'recovered' => $recovered,
            'pending' => $pending,
            'expired' => $expired,
            'review' => $review,
            'failed' => $failed,
            'cursor_created_at' => $cursor['created_at'] ?? null,
            'cursor_id' => $cursor['id'] ?? null,
            'cursor_wrapped' => $wrapped,
            'pages_fetched' => $pagesFetched,
            'scan_cap' => $scanCap,
            'budget_exhausted' => hrtime(true) >= $executionDeadline,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        if ($failed > 0) {
            $this->error('klikqris:reconcile-balance-top-ups completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('klikqris:reconcile-balance-top-ups completed: '.$summary);

        return self::SUCCESS;
    }

    public static function schedulerMutexMinutes(): int
    {
        $executionBudgetSeconds = max(
            1.0,
            (float) config('services.klikqris.reconcile_max_execution_seconds', 45),
        );

        return max(
            1,
            (int) ceil(
                ($executionBudgetSeconds + ProviderVerificationProfiles::requiredLeaseSeconds()) / 60,
            ),
        );
    }

    private function validatedInteger(string $option, int $max): ?int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT);

        return $value === false || $value < 1 || $value > $max ? null : $value;
    }

    private function cursorKey(KlikQrisEnvironment $environment): string
    {
        return 'klikqris:reconcile-balance-top-ups:cursor:'.$environment->value;
    }

    private function cursorAfterOverride(): string|false|null
    {
        $raw = trim((string) $this->option('after'));
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format('c');
        } catch (\Exception) {
            return false;
        }
    }

    /** @return array{created_at: string, id: string}|null */
    private function resumeCursor(string $cursorKey, string|false|null $afterOverride): ?array
    {
        if (is_string($afterOverride)) {
            return $this->cursorPayload($afterOverride);
        }

        $cached = Cache::get($cursorKey);
        if (! is_array($cached) || ! isset($cached['created_at'], $cached['id'])) {
            return null;
        }

        try {
            new DateTimeImmutable((string) $cached['created_at']);
        } catch (\Exception) {
            return null;
        }

        return $this->cursorPayload((string) $cached['created_at'], (string) $cached['id']);
    }

    /** @param array{created_at: string, id: string} $cursor */
    private function applyCursor(Builder $query, array $cursor): Builder
    {
        $createdAt = new DateTimeImmutable($cursor['created_at']);

        return $query->where(function (Builder $query) use ($createdAt, $cursor): Builder {
            return $query
                ->where('created_at', '>', $createdAt)
                ->orWhere(function (Builder $query) use ($createdAt, $cursor): Builder {
                    return $query
                        ->where('created_at', '=', $createdAt)
                        ->where('id', '>', $cursor['id']);
                });
        });
    }

    /** @return array{created_at: string, id: string} */
    private function cursorFor(StoreBalanceTopUp $topUp): array
    {
        $createdAt = $topUp->created_at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($topUp->created_at)
            : new DateTimeImmutable((string) $topUp->created_at);

        return $this->cursorPayload($createdAt->format('c'), (string) $topUp->getKey());
    }

    /** @return array{created_at: string, id: string} */
    private function cursorPayload(string $createdAt, string $id = ''): array
    {
        return ['created_at' => $createdAt, 'id' => $id];
    }

    private function cursorScanCap(): int
    {
        return max(1, (int) config('services.klikqris.reconcile_cursor_scan_cap', 500));
    }

    private function executionDeadline(): int
    {
        if ($this->deadlineFactory !== null) {
            return (int) ($this->deadlineFactory)();
        }

        $budgetSeconds = max(
            1.0,
            (float) config('services.klikqris.reconcile_max_execution_seconds', 45),
        );

        return hrtime(true) + (int) ($budgetSeconds * 1_000_000_000);
    }

    private function reportRow(StoreBalanceTopUp $topUp, string $outcome, int $startedAt): void
    {
        $ageSeconds = $topUp->created_at === null
            ? 0
            : max(0, now()->diffInSeconds($topUp->created_at));
        $this->line('top_up='.$topUp->getKey().' outcome='.$outcome.' age_seconds='.$ageSeconds);
        Log::info('klikqris.balance_top_up_reconciliation.row', [
            'top_up_id' => (string) $topUp->getKey(),
            'merchant_ref_hash' => $this->referenceHash($topUp->merchant_ref),
            'outcome' => $outcome,
            'age_seconds' => $ageSeconds,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);
    }

    private function referenceHash(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : hash('sha256', $reference);
    }

    private function isRetryable(Throwable $exception): bool
    {
        return $exception instanceof KlikQrisStatusUnavailableException;
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
