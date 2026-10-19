<?php

namespace App\Console\Commands;

use App\Enums\CustomerUsdtFundingReservationOutcome;
use App\Models\Customer;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Timed release of confirmed USDT funding targets that never settled.
 *
 * The single active-target slot is deliberately held while money is possible,
 * but an unpaid target used to hold it forever. After the payment window plus a
 * grace period, a target with no economic resolution and no in-flight
 * settlement lease is released together with an immutable `expired_unpaid`
 * resolution. The release moves no money: a transfer that arrives later can
 * never be credited against the released reservation and instead follows the
 * existing manual-review path.
 */
final class ReleaseExpiredUsdtFunding extends Command
{
    protected $signature = 'usdt-funding:release-expired
        {--limit=25 : Maximum reservations to release (1-100).}
        {--grace-minutes=60 : Minutes after the payment deadline before a target may be released (1-1440).}
        {--json : Print the sanitized result as JSON.}';

    protected $description = 'Release expired unpaid USDT funding targets so the Customer can start a new top-up without moving money.';

    public function handle(CustomerUsdtFundingReservationService $reservations): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Limit must be an integer between 1 and 100.');

            return self::FAILURE;
        }
        $grace = filter_var($this->option('grace-minutes'), FILTER_VALIDATE_INT);
        if ($grace === false || $grace < 1 || $grace > 1440) {
            $this->error('Grace minutes must be an integer between 1 and 1440.');

            return self::FAILURE;
        }

        $rows = DB::table('customer_usdt_funding_reservations as reservations')
            ->join('customer_usdt_top_ups as targets', 'targets.reservation_id', '=', 'reservations.id')
            ->leftJoin('customer_usdt_top_up_settlement_work as work', 'work.top_up_id', '=', 'targets.id')
            ->where('reservations.state', 'expired_unresolved')
            ->where('reservations.expires_at', '<=', now()->subMinutes($grace))
            ->where(fn ($query) => $query->whereNull('work.lease_until')->orWhere('work.lease_until', '<=', now()))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('customer_usdt_top_up_resolutions as resolutions')
                ->whereColumn('resolutions.top_up_id', 'targets.id'))
            ->orderBy('reservations.expires_at')
            ->orderBy('reservations.id')
            ->limit($limit)
            ->get(['reservations.id as reservation_id', 'reservations.customer_id', 'targets.id as top_up_id']);

        $released = 0;
        $replayed = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $customer = Customer::query()->find($row->customer_id);
            if ($customer === null) {
                $skipped++;

                continue;
            }

            try {
                $result = $reservations->releaseExpiredUnpaid(
                    $customer,
                    (int) $row->reservation_id,
                    CustomerUsdtFundingRelease::expiredUnpaid(
                        'expiry-sweep:'.$row->top_up_id,
                        'usdt-funding:release-expired',
                    ),
                );
            } catch (Throwable) {
                // A concurrent settlement, review or slot release always wins.
                $skipped++;

                continue;
            }

            match ($result->outcome) {
                CustomerUsdtFundingReservationOutcome::Released => $released++,
                CustomerUsdtFundingReservationOutcome::Replayed => $replayed++,
                default => $skipped++,
            };
        }

        $report = [
            'scanned' => $rows->count(),
            'released' => $released,
            'replayed' => $replayed,
            'skipped' => $skipped,
            'grace_minutes' => $grace,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Released %d expired unpaid target(s); %d skipped.',
                $released + $replayed,
                $skipped,
            ));
        }

        return self::SUCCESS;
    }
}
