<?php

namespace App\Services\UsdtTopUps;

use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Redis is not authority. A bounded DB lease owns each preparation attempt. */
final class UsdtTopUpPreparation
{
    public function __construct(private readonly UsdtTopUpRail $rail) {}

    public function recover(int $limit = 25): int
    {
        $this->outsideTransaction();
        $ids = DB::table('customer_usdt_top_up_work')
            ->whereIn('state', ['pending', 'creating', 'creation_unknown'])
            ->where('next_attempt_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->orderBy('next_attempt_at')->orderBy('top_up_id')->limit(max(1, min(100, $limit)))->pluck('top_up_id');
        $processed = 0;
        foreach ($ids as $id) {
            $processed += $this->prepare($id) ? 1 : 0;
        }

        return $processed;
    }

    public function prepare(string $id): bool
    {
        $this->outsideTransaction();
        $claim = DB::transaction(function () use ($id): ?array {
            $work = DB::table('customer_usdt_top_up_work')->where('top_up_id', $id)->lockForUpdate()->first();
            if ($work === null || ! in_array($work->state, ['pending', 'creating', 'creation_unknown'], true)
                || CarbonImmutable::parse($work->next_attempt_at)->isFuture()
                || ($work->lease_until !== null && CarbonImmutable::parse($work->lease_until)->isFuture())) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $version = (int) $work->version + 1;
            DB::table('customer_usdt_top_up_work')->where('top_up_id', $id)->update([
                'state' => 'creating', 'version' => $version, 'attempts' => (int) $work->attempts + 1,
                'lease_token' => $token, 'lease_until' => now()->addMinutes(2), 'updated_at' => now(),
            ]);

            return ['token' => $token, 'version' => $version, 'attempts' => (int) $work->attempts + 1];
        }, 5);
        if ($claim === null) {
            return false;
        }
        $target = CustomerUsdtTopUp::query()->findOrFail($id);
        $invoice = null;
        $error = null;
        try {
            $invoice = $this->rail->createOrRecover(Customer::query()->findOrFail($target->customer_id),
                $target->reference, UsdtMicroAmount::fromUnits($target->payable_micro), $target->expires_at, $target);
        } catch (Throwable) {
            // Do not leak RPC endpoints/payloads or drop the confirmed slot.
            // The local rail has no remote create; unknown means our observed
            // preparation outcome, not a fictional custody-provider invoice.
            $error = 'rail_preparation_unresolved';
        }
        DB::transaction(function () use ($id, $claim, $target, $invoice, $error): void {
            $work = DB::table('customer_usdt_top_up_work')->where('top_up_id', $id)->lockForUpdate()->first();
            if ($work === null || $work->lease_token !== $claim['token'] || (int) $work->version !== $claim['version']) {
                return;
            }
            DB::table('customer_usdt_top_up_work')->where('top_up_id', $id)->update([
                'state' => $invoice !== null ? 'ready' : ($target->expires_at->isFuture() ? 'creation_unknown' : 'expired_unresolved'),
                'version' => $claim['version'] + 1, 'lease_token' => null, 'lease_until' => null,
                'rail_invoice_id' => $invoice?->id, 'last_error' => $error,
                'next_attempt_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $claim['attempts'])))), 'updated_at' => now(),
            ]);
        }, 5);

        return true;
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('USDT provider preparation cannot run inside a transaction.');
        }
    }
}
