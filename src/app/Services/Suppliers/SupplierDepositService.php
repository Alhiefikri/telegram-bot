<?php

namespace App\Services\Suppliers;

use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierDeposit;
use App\Models\Supplier;
use App\Models\SupplierDeposit;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Supplier funding creates operator payment instructions, never Customer credits. */
final class SupplierDepositService
{
    private const ACTIVE = ['requested', 'sending', 'unknown', 'pending', 'cancel_requested', 'review'];

    public function __construct(private readonly BuyMailClient $client, private readonly AuditLogger $audit) {}

    public function create(Supplier $initial, int $amount, string $key, User $actor): SupplierDeposit
    {
        if ($actor->role?->canManageStoreOperations() !== true || $amount < 5000 || $amount > 5000000
            || preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key) !== 1) {
            throw new InvalidOrderTransition('Invalid supplier deposit instruction.');
        }

        return DB::transaction(function () use ($initial, $amount, $key, $actor): SupplierDeposit {
            $supplier = Supplier::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $existing = SupplierDeposit::query()->where('supplier_id', $supplier->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->amount !== $amount) {
                    throw new InvalidOrderTransition('Deposit identity was already claimed with a different amount.');
                }

                return $existing;
            }
            if ($supplier->provider !== 'buymail' || ! $supplier->is_active || $supplier->external_account_id === null
                || SupplierDeposit::query()->where('supplier_id', $supplier->id)->whereIn('state', self::ACTIVE)->exists()) {
                throw new InvalidOrderTransition('Pay, cancel or recover the existing deposit before creating another.');
            }
            $deposit = SupplierDeposit::create([
                'supplier_id' => $supplier->id, 'idempotency_key' => $key, 'account_id_snapshot' => $supplier->external_account_id,
                'amount' => $amount, 'state' => 'requested', 'operation' => 'create', 'requested_by_user_id' => $actor->id,
                'next_attempt_at' => now(),
                'credential_fingerprint' => hash('sha256', $supplier->api_key),
            ]);
            $this->audit->log('supplier.deposit_requested', $deposit, new: ['supplier_id' => $supplier->id, 'amount' => $amount], user: $actor);
            DB::afterCommit(fn () => ProcessSupplierDeposit::dispatch((string) $deposit->id));

            return $deposit;
        }, 5);
    }

    public function cancel(SupplierDeposit $initial, User $actor): void
    {
        if ($actor->role?->canManageStoreOperations() !== true) {
            throw new InvalidOrderTransition('Supplier deposit cancellation is unavailable.');
        }
        DB::transaction(function () use ($initial, $actor): void {
            Supplier::query()->whereKey($initial->supplier_id)->lockForUpdate()->firstOrFail();
            $deposit = SupplierDeposit::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($deposit->state === 'cancel_requested') {
                return;
            }
            if ($deposit->state !== 'pending' || $deposit->external_id === null || $deposit->lease_expires_at?->isFuture()) {
                throw new InvalidOrderTransition('Only a known unpaid deposit can be cancelled.');
            }
            $deposit->update(['state' => 'cancel_requested', 'operation' => 'cancel', 'next_attempt_at' => now()]);
            $this->audit->log('supplier.deposit_cancel_requested', $deposit, user: $actor);
            DB::afterCommit(fn () => ProcessSupplierDeposit::dispatch((string) $deposit->id));
        }, 5);
    }

    public function process(string $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new SupplierUnavailable('financial_lock');
        }
        $request = DB::transaction(function () use ($id): ?array {
            $initial = SupplierDeposit::find($id);
            if ($initial === null) {
                return null;
            }
            $supplier = Supplier::query()->whereKey($initial->supplier_id)->lockForUpdate()->firstOrFail();
            $deposit = SupplierDeposit::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($deposit->state, self::ACTIVE, true) || $deposit->state === 'review' || $deposit->lease_expires_at?->isFuture() || $deposit->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($supplier->external_account_id !== $deposit->account_id_snapshot
                || ($deposit->external_id === null && $deposit->dispatched_at !== null && ($deposit->credential_fingerprint === null || ! hash_equals($deposit->credential_fingerprint, hash('sha256', $supplier->api_key))))
                || ($deposit->external_id === null && $deposit->dispatched_at?->lt(now()->subDays(7)->addMinutes(5)))) {
                $deposit->update(['state' => 'review', 'last_error' => 'deposit_recovery_required', 'next_attempt_at' => null]);

                return null;
            }
            $cancel = $deposit->operation === 'cancel' && $deposit->external_id !== null;
            $deposit->update(['state' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(3), 'dispatched_at' => $deposit->dispatched_at ?? now()]);

            return [$supplier, $deposit, $cancel];
        }, 5);
        if ($request === null) {
            return;
        }
        [$supplier, $deposit, $cancel] = $request;
        $row = null;
        $error = null;
        try {
            if ($cancel) {
                try {
                    $this->client->cancelDeposit($supplier, $deposit->external_id);
                } catch (SupplierUnavailable) {
                    // Even a successful cancellation may return a different
                    // envelope; GET resolves cancel-versus-paid and timeouts.
                }
                $row = $this->client->deposit($supplier, $deposit->external_id);
            } elseif ($deposit->external_id !== null) {
                $row = $this->client->deposit($supplier, $deposit->external_id);
            } else {
                $row = $this->client->createDeposit($supplier, $deposit->amount, $deposit->idempotency_key);
            }
        } catch (Throwable) {
            $error = 'deposit_transport_unavailable';
        }
        DB::transaction(function () use ($deposit, $row, $error): void {
            $supplier = Supplier::query()->whereKey($deposit->supplier_id)->lockForUpdate()->firstOrFail();
            $locked = SupplierDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->claim_token !== $deposit->claim_token) {
                return;
            }
            $locked->forceFill(['claim_token' => null, 'lease_expires_at' => null]);
            if ($row === null || $row['amount'] !== $locked->amount) {
                $locked->forceFill(['state' => 'unknown', 'last_error' => $error ?? 'deposit_identity_mismatch', 'next_attempt_at' => now()->addMinute()])->save();

                return;
            }
            $this->apply($supplier, $locked, $row);
        }, 5);
    }

    public function reconcileKnownDeposit(Supplier $supplier, string $externalId): SupplierDeposit
    {
        $row = $this->client->deposit($supplier, $externalId);

        return DB::transaction(function () use ($supplier, $row): SupplierDeposit {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if ($locked->provider !== 'buymail' || $locked->external_account_id === null || $locked->external_account_id !== $supplier->external_account_id) {
                throw new SupplierUnavailable('account_identity_mismatch');
            }
            $deposit = SupplierDeposit::query()->where('supplier_id', $locked->id)->where('external_id', $row['id'])->lockForUpdate()->first();
            if ($deposit === null && SupplierDeposit::query()->where('supplier_id', $locked->id)
                ->whereNull('external_id')->whereIn('state', self::ACTIVE)->exists()) {
                // A webhook can arrive before the create response. Its external
                // ID is not proof of which local intent created it; retry after
                // the immutable create identity has recovered its own result.
                throw new SupplierUnavailable('deposit_identity_unresolved');
            }
            $deposit ??= SupplierDeposit::create([
                'supplier_id' => $locked->id, 'idempotency_key' => 'external:'.hash('sha256', $row['id']),
                'external_id' => $row['id'], 'account_id_snapshot' => $locked->external_account_id,
                'amount' => $row['amount'], 'state' => $row['status'], 'operation' => 'create',
            ]);
            $this->apply($locked, $deposit, $row);

            return $deposit->fresh();
        }, 5);
    }

    private function apply(Supplier $supplier, SupplierDeposit $deposit, array $row): void
    {
        $wasPaid = $deposit->state === 'paid' && $deposit->paid_at !== null;
        if (($deposit->external_id !== null && $deposit->external_id !== $row['id']) || $deposit->amount !== $row['amount']) {
            throw new SupplierUnavailable('deposit_identity_mismatch');
        }
        if (($deposit->state === 'paid' && $row['status'] !== 'paid')
            || (in_array($deposit->state, ['cancelled', 'expired'], true) && $row['status'] === 'pending')) {
            return;
        }
        $deposit->forceFill([
            'external_id' => $row['id'], 'state' => $row['status'] === 'pending' && $deposit->operation === 'cancel' ? 'cancel_requested' : $row['status'],
            'payment_url' => $row['payment_url'], 'expires_at' => CarbonImmutable::parse($row['expires_at'])->timezone(config('app.timezone')),
            'paid_at' => $row['status'] === 'paid' ? ($deposit->paid_at ?? now()) : $deposit->paid_at,
            'claim_token' => null, 'lease_expires_at' => null, 'last_error' => null,
            'next_attempt_at' => $row['status'] === 'pending' ? now()->addMinute() : null,
        ])->save();
        if ($row['status'] === 'paid' && ! $wasPaid) {
            // No additive balance write: profile synchronization observes the
            // authoritative wallet, which may already include this credit.
            $supplier->forceFill(['sync_expires_at' => null, 'version' => $supplier->version + 1])->save();
        }
    }
}
