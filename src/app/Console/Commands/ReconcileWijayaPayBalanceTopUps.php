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
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Reconcile WijayaPay Store Balance top-ups through the authoritative status
 * endpoint. No create request is ever inferred here.
 */
final class ReconcileWijayaPayBalanceTopUps extends Command
{
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
        $this->candidateQuery()->limit($chunk)->get()->each(function (StoreBalanceTopUp $topUp) use ($topUps, &$counts, $maxTopUps, $gate): void {
            if ($counts['eligible'] >= $maxTopUps || ProviderVerificationProfiles::stopReason() !== null || $gate->isCoolingDown()) {
                return;
            }

            $counts['eligible']++;
            if ($topUps->expireIfDue($topUp)) {
                $counts['expired']++;

                return;
            }

            try {
                $result = $topUps->reconcile($topUp);
            } catch (Throwable $exception) {
                report($exception);
                $counts['failed']++;

                return;
            }

            match ($result->outcome()) {
                StoreBalanceTopUpOutcome::Payable => $counts['pending']++,
                StoreBalanceTopUpOutcome::Expired => $counts['expired']++,
                StoreBalanceTopUpOutcome::InvalidProviderResult => $counts['review']++,
                StoreBalanceTopUpOutcome::Creating,
                StoreBalanceTopUpOutcome::RecoveryPending,
                StoreBalanceTopUpOutcome::ProviderTotalPending => $counts['pending']++,
                default => $counts['pending']++,
            };

            if ($topUp->fresh()?->status === StoreBalanceTopUpStatus::Paid) {
                $counts['paid']++;
            }
        });

        $rateLimited = $gate->isCoolingDown();
        $stopReason = $rateLimited ? 'provider_throttled' : (ProviderVerificationProfiles::stopReason() ?? ($counts['eligible'] >= $maxTopUps ? 'top_up_limit' : 'page_complete'));
        $carryOver = $rateLimited || ProviderVerificationProfiles::stopReason() !== null || $counts['eligible'] >= min($chunk, $maxTopUps);
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
        return StoreBalanceTopUp::query()
            ->select(['id', 'provider', 'status', 'provider_create_state', 'expires_at', 'provider_expires_at'])
            ->whereRaw('LOWER(TRIM(provider)) = ?', [PaymentProvider::WIJAYAPAY])
            ->whereNotNull('merchant_ref')
            ->whereIn('status', [
                StoreBalanceTopUpStatus::Pending->value,
                StoreBalanceTopUpStatus::Payable->value,
                StoreBalanceTopUpStatus::RecoveryPending->value,
                StoreBalanceTopUpStatus::Failed->value,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('provider_create_state')
                    ->orWhere('provider_create_state', '!=', 'provider_unavailable');
            })
            ->orderBy('id');
    }
}
