<?php

namespace App\Console\Commands;

use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPayRequestGate;
use App\Support\PaymentProvider;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reconcile WijayaPay Store Balance top-ups through the authoritative status
 * endpoint. No create request is ever inferred here.
 *
 * A persisted created_at/id cursor rotates through the whole unresolved set,
 * so old pending targets cannot starve newer ones. Locally expired targets
 * stay eligible for a bounded late-payment lookback; expiry is never proof
 * that the customer did not pay.
 */
final class ReconcileWijayaPayBalanceTopUps extends Command
{
    private const CURSOR_TTL_SECONDS = 86_400;

    protected $signature = 'wijayapay:reconcile-balance-top-ups {--chunk=100} {--max-top-ups=10}';

    protected $description = 'Reconcile WijayaPay Store Balance top-ups from authoritative status';

    public function handle(
        StoreBalanceTopUpService $topUps,
        WijayaPayProviderReadiness $readiness,
    ): int {
        $deadline = hrtime(true) + (int) (max(1.0, (float) config('services.wijayapay.reconcile_max_execution_seconds', 45)) * 1_000_000_000);

        return ProviderVerificationProfiles::until($deadline, fn (): int => $this->reconcile($topUps, $readiness));
    }

    private function reconcile(StoreBalanceTopUpService $topUps, WijayaPayProviderReadiness $readiness): int
    {
        $chunk = (int) $this->option('chunk');
        $maxTopUps = (int) $this->option('max-top-ups');
        if ($chunk < 1 || $chunk > 1000 || $maxTopUps < 1 || $maxTopUps > 1000) {
            $this->error('Chunk and max top-ups must be between 1 and 1000.');

            return self::FAILURE;
        }

        if (! $readiness->configurationReport()->isReady()) {
            $this->info('wijayapay:reconcile-balance-top-ups skipped: provider not ready.');

            return self::SUCCESS;
        }

        $counts = [
            'eligible' => 0,
            'paid' => 0,
            'expired' => 0,
            'pending' => 0,
            'review' => 0,
            'failed' => 0,
        ];

        $gate = new WijayaPayRequestGate;
        $cursorKey = 'wijayapay:reconcile-balance-top-ups:cursor';
        $cursor = $this->resumeCursor($cursorKey);
        $wrapped = false;
        $pagesFetched = 0;
        $scanCap = $this->cursorScanCap();

        do {
            $pagesFetched++;
            $page = $this->candidateQuery()
                ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($page->isEmpty()) {
                $wrapped = true;

                break;
            }

            $stoppedEarly = false;
            foreach ($page as $topUp) {
                if ($counts['eligible'] >= $maxTopUps
                    || ProviderVerificationProfiles::stopReason() !== null
                    || $gate->isCoolingDown()
                ) {
                    $stoppedEarly = true;

                    break;
                }

                $counts['eligible']++;
                $cursor = $this->cursorFor($topUp);

                // A local deadline closes the payable presentation but not the
                // authoritative verification path. The reconcile below still
                // runs so a provider-confirmed late payment can be settled by
                // the existing late-settlement rules.
                $topUps->expireIfDue($topUp);

                try {
                    $result = $topUps->reconcile($topUp->fresh() ?? $topUp);
                } catch (Throwable $exception) {
                    report($exception);
                    $counts['failed']++;

                    continue;
                }

                $fresh = $topUp->fresh();
                match (true) {
                    $fresh?->status === StoreBalanceTopUpStatus::Paid => $counts['paid']++,
                    $fresh?->status === StoreBalanceTopUpStatus::Expired,
                    $result->outcome() === StoreBalanceTopUpOutcome::Expired => $counts['expired']++,
                    $fresh?->status === StoreBalanceTopUpStatus::InvalidProviderResult,
                    $result->outcome() === StoreBalanceTopUpOutcome::InvalidProviderResult => $counts['review']++,
                    default => $counts['pending']++,
                };
            }

            $reachedEnd = $page->count() < $chunk;
            if ($reachedEnd && ! $stoppedEarly) {
                $wrapped = true;
            }
        } while (! $reachedEnd
            && ! $stoppedEarly
            && $counts['eligible'] < $maxTopUps
            && ProviderVerificationProfiles::stopReason() === null
            && $pagesFetched < $scanCap);

        if ($wrapped) {
            Cache::forget($cursorKey);
            $cursor = null;
        } elseif ($cursor !== null) {
            Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
        }

        $rateLimited = $gate->isCoolingDown();
        $stopReason = $rateLimited
            ? 'provider_throttled'
            : (ProviderVerificationProfiles::stopReason()
                ?? ($counts['eligible'] >= $maxTopUps
                    ? 'top_up_limit'
                    : ($pagesFetched >= $scanCap ? 'scan_limit' : 'page_complete')));
        $carryOver = ! $wrapped || $rateLimited || ProviderVerificationProfiles::stopReason() !== null;
        $this->line('wijayapay:reconcile-balance-top-ups sweep: requests='.ProviderVerificationProfiles::requests().' stop_reason='.$stopReason.' carry_over='.($carryOver ? 'yes' : 'no'));

        $this->info('wijayapay:reconcile-balance-top-ups completed: '
            .'eligible='.$counts['eligible']
            .' paid='.$counts['paid']
            .' expired='.$counts['expired']
            .' pending='.$counts['pending']
            .' review='.$counts['review']
            .' failed='.$counts['failed']);

        return $rateLimited ? self::FAILURE : self::SUCCESS;
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function candidateQuery(): Builder
    {
        $lookback = now()->subHours(max(1, (int) config('services.wijayapay.reconcile_terminal_lookback_hours', 72)));

        return StoreBalanceTopUp::query()
            ->select(['id', 'provider', 'status', 'provider_create_state', 'expires_at', 'provider_expires_at', 'created_at'])
            ->whereRaw('LOWER(TRIM(provider)) = ?', [PaymentProvider::WIJAYAPAY])
            ->whereNotNull('merchant_ref')
            ->where(function (Builder $query) use ($lookback): void {
                $query->whereIn('status', [
                    StoreBalanceTopUpStatus::Pending->value,
                    StoreBalanceTopUpStatus::Payable->value,
                    StoreBalanceTopUpStatus::RecoveryPending->value,
                    StoreBalanceTopUpStatus::Failed->value,
                ])->orWhere(function (Builder $expired) use ($lookback): void {
                    $expired->where('status', StoreBalanceTopUpStatus::Expired->value)
                        ->where(function (Builder $deadline) use ($lookback): void {
                            $deadline->where('provider_expires_at', '>=', $lookback)
                                ->orWhere(function (Builder $fallback) use ($lookback): void {
                                    $fallback->whereNull('provider_expires_at')
                                        ->where('expires_at', '>=', $lookback);
                                });
                        });
                });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('provider_create_state')
                    ->orWhere('provider_create_state', '!=', 'provider_unavailable');
            });
    }

    /** @return array{created_at: string, id: string}|null */
    private function resumeCursor(string $cursorKey): ?array
    {
        $cached = Cache::get($cursorKey);
        if (! is_array($cached) || ! isset($cached['created_at'], $cached['id'])) {
            return null;
        }

        try {
            new DateTimeImmutable((string) $cached['created_at']);
        } catch (\Exception) {
            return null;
        }

        return [
            'created_at' => (string) $cached['created_at'],
            'id' => (string) $cached['id'],
        ];
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

        return [
            'created_at' => $createdAt->format('c'),
            'id' => (string) $topUp->getKey(),
        ];
    }

    private function cursorScanCap(): int
    {
        return max(1, (int) config('services.wijayapay.reconcile_cursor_scan_cap', 500));
    }
}
