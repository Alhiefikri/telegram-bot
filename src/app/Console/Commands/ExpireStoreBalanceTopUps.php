<?php

namespace App\Console\Commands;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expire Store Balance top-ups whose deadline has passed.
 *
 * This is a bounded shared sweep for every top-up rail: it performs no
 * provider I/O and never credits a balance. Provider-specific reconciliation
 * commands keep their own authoritative status work.
 */
final class ExpireStoreBalanceTopUps extends Command
{
    protected $signature = 'store-balance:expire-top-ups {--chunk=100}';

    protected $description = 'Expire due Store Balance top-ups without provider lookups';

    public function handle(StoreBalanceTopUpService $topUps): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1 || $chunk > 1000) {
            $this->error('The chunk size must be between 1 and 1000.');

            return self::FAILURE;
        }

        $expired = 0;
        $examined = 0;
        $now = now();

        $this->dueQuery($now)->chunkById($chunk, function ($candidates) use ($topUps, &$expired, &$examined): void {
            foreach ($candidates as $topUp) {
                $examined++;
                if ($topUps->expireIfDue($topUp)) {
                    $expired++;
                }
            }
        });

        $this->info('store-balance:expire-top-ups completed: examined='.$examined.' expired='.$expired);

        return self::SUCCESS;
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function dueQuery(mixed $now): Builder
    {
        return StoreBalanceTopUp::query()
            ->select(['id', 'status', 'expires_at', 'provider_expires_at'])
            ->whereIn('status', [
                StoreBalanceTopUpStatus::Pending->value,
                StoreBalanceTopUpStatus::Payable->value,
                StoreBalanceTopUpStatus::RecoveryPending->value,
                StoreBalanceTopUpStatus::AwaitingConfirmation->value,
                StoreBalanceTopUpStatus::Failed->value,
            ])
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where(fn (Builder $inner) => $inner->whereNotNull('expires_at')->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $inner) => $inner->whereNotNull('provider_expires_at')->where('provider_expires_at', '<=', $now));
            })
            ->orderBy('id');
    }
}
