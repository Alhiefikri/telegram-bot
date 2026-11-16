<?php

namespace App\Services\CustomerApi;

use App\Enums\BalancePaymentPreference;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the create-order claim keyed by Customer + operation + Idempotency-Key.
 * Database uniqueness protects identity; admission serializes per Customer to
 * bound non-accepted history. Each claim stores the canonical fingerprint,
 * admitted credential and preference snapshot. Accepted claims never expire;
 * expired non-accepted claims can be recovered or safely reclaimed.
 */
final class CustomerApiOrderClaims
{
    /** Pre-order lease length. Successful claims never expire. */
    public const LEASE_SECONDS = 30;

    /** Bounded wait for a concurrent identical request before answering in-progress. */
    public const WAIT_SECONDS = 2;

    public const RETENTION_HOURS = 24;

    public const MAX_PENDING_CLAIMS = 10_000;

    private const CLEANUP_BATCH = 100;

    /**
     * Upper bound on one takeover attempt's row-lock wait, far below
     * {@see WAIT_SECONDS} so a holder that still owns the unconditional claim
     * lock cannot push the public deadline past its documented bound. A timed-out
     * attempt is a lost takeover, never an error and never a lock steal.
     */
    private const LOCK_WAIT_MILLISECONDS = 500;

    private const POLL_MICROSECONDS = 100_000;

    public function acquire(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request): CustomerApiOrderAcquisition
    {
        $fingerprint = $request->fingerprint();
        $deadline = hrtime(true) + self::WAIT_SECONDS * 1_000_000_000;
        $sawExisting = false;

        while (hrtime(true) < $deadline) {
            $existing = $this->find($principal->customerId, $request->idempotencyKey);
            if ($existing === null) {
                $sawExisting = false;
                $claim = $this->insert($principal, $request, $fingerprint, $deadline);
                if ($claim !== null) {
                    return CustomerApiOrderAcquisition::leased($claim);
                }
            } else {
                $sawExisting = true;
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new CustomerApiFailure(409, 'idempotency_conflict', 'This Idempotency-Key was already used with a different request body.');
                }
                if ($existing->state === CustomerApiOrderClaim::STATE_ACCEPTED) {
                    return CustomerApiOrderAcquisition::accepted((string) $existing->order_id);
                }
                if ($this->takeOver($principal, $existing, $deadline)) {
                    return CustomerApiOrderAcquisition::leased($existing);
                }
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining > 0) {
                usleep((int) min(self::POLL_MICROSECONDS, $remaining / 1000));
            }
        }

        if (! $sawExisting) {
            throw CustomerApiFailure::unavailable();
        }

        throw new CustomerApiFailure(
            409,
            'in_progress',
            'An identical request is still being processed. Retry shortly with the same Idempotency-Key.',
            ['Retry-After' => '2'],
        );
    }

    /** Bind the accepted Order and the immutable API origin marker to this claim. */
    public function accept(CustomerApiOrderClaim $claim, Order $order): void
    {
        $accepted = CustomerApiOrderClaim::query()->whereKey($claim->getKey())
            ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
            ->where('attempt_token', $claim->attempt_token)
            ->lockForUpdate()->first();
        if ($accepted === null) {
            throw new CustomerApiFailure(409, 'in_progress', 'This request is owned by another attempt. Retry with the same Idempotency-Key.', ['Retry-After' => '2']);
        }
        $accepted->forceFill([
            'state' => CustomerApiOrderClaim::STATE_ACCEPTED,
            'order_id' => $order->getKey(),
            'attempt_token' => null,
            'lease_expires_at' => null,
        ])->save();

        $origin = Order::query()->whereKey($order->getKey())->first();
        if ($origin !== null && $origin->api_order_claim_id === null) {
            $origin->forceFill(['api_order_claim_id' => $claim->getKey()])->save();
        }
    }

    /**
     * Release our fenced lease after a financially rolled-back attempt so the
     * same key can be retried. Never marks the claim accepted and never clears
     * a lease another attempt already took over.
     */
    public function abandon(CustomerApiPrincipal $principal, CustomerApiOrderClaim $claim): void
    {
        try {
            DB::transaction(function () use ($principal, $claim): void {
                Customer::query()->whereKey($principal->customerId)->sharedLock()->firstOrFail();
                $principal->revalidateForMutation();
                CustomerApiOrderClaim::query()->whereKey($claim->getKey())
                    ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
                    ->where('attempt_token', $claim->attempt_token)
                    ->update(['attempt_token' => null, 'lease_expires_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable) {
            // A lost lease is bounded by its expiry; never mask the original failure.
        }
    }

    private function insert(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request, string $fingerprint, int $deadline): ?CustomerApiOrderClaim
    {
        $lockWait = (int) min(self::LOCK_WAIT_MILLISECONDS, ($deadline - hrtime(true)) / 1_000_000);
        if ($lockWait < 1) {
            return null;
        }
        $atCapacity = false;
        try {
            $claim = DB::transaction(function () use ($principal, $request, $fingerprint, $deadline, $lockWait, &$atCapacity): ?CustomerApiOrderClaim {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL lock_timeout = '.$lockWait);
                }
                // Serializes admissions and cleanup for this Customer, and follows
                // the same Customer -> credential order as rotation/disable.
                Customer::query()->whereKey($principal->customerId)->lockForUpdate()->firstOrFail();
                $principal->revalidateForMutation();
                if ($this->find($principal->customerId, $request->idempotencyKey) !== null) {
                    return null;
                }
                if (! Product::query()->publicVisible()->whereKey($request->productId)->exists()) {
                    throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
                }

                $expired = $this->withoutProtectedOrders(CustomerApiOrderClaim::query()->where('customer_id', $principal->customerId))
                    ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
                    ->where('created_at', '<', now()->subHours(self::RETENTION_HOURS))
                    ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))
                    ->orderBy('id')->limit(self::CLEANUP_BATCH);
                if (DB::getDriverName() === 'pgsql') {
                    $expired->lock('for update skip locked');
                }
                $ids = $expired->pluck('id');
                if ($ids->isNotEmpty()) {
                    // Preserve legacy/recovery orders even if their claim never
                    // reached acceptance. Checkout uses this same durable key.
                    $keys = $ids->mapWithKeys(fn ($id) => [$id => 'api-'.substr(hash('sha256', 'api-order:'.$id), 0, 60)]);
                    $protected = Order::query()->whereIn('api_order_claim_id', $ids)
                        ->orWhereIn('checkout_key', $keys->values())->get(['api_order_claim_id', 'checkout_key']);
                    $ids = $ids->reject(fn ($id) => $protected->contains(
                        fn (Order $order) => (string) $order->api_order_claim_id === (string) $id || $order->checkout_key === $keys[$id],
                    ));
                    if ($ids->isNotEmpty()) {
                        DB::table('customer_api_order_claims')->whereIn('id', $ids)->delete();
                    }
                }
                if ($this->withoutProtectedOrders(CustomerApiOrderClaim::query()->where('customer_id', $principal->customerId))
                    ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)->count() >= self::MAX_PENDING_CLAIMS) {
                    $atCapacity = true;

                    return null;
                }

                $preference = $request->preferenceOverride ?? $this->accountPreference($principal->customerId);
                $remainingLockWait = (int) min(self::LOCK_WAIT_MILLISECONDS, ($deadline - hrtime(true)) / 1_000_000);
                if ($remainingLockWait < 1) {
                    return null;
                }
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL lock_timeout = '.$remainingLockWait);
                }

                return CustomerApiOrderClaim::create([
                    'customer_id' => $principal->customerId,
                    'operation' => CustomerApiOrderClaim::OPERATION_CREATE_ORDER,
                    'idempotency_key' => $request->idempotencyKey,
                    'fingerprint' => $fingerprint,
                    'credential_id' => $principal->credentialId,
                    'state' => CustomerApiOrderClaim::STATE_IN_PROGRESS,
                    'attempt_token' => bin2hex(random_bytes(16)),
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                    'preference' => $preference->value,
                    'preference_source' => $request->preferenceOverride === null ? 'account' : 'override',
                    'product_id' => $request->productId,
                    'quantity' => $request->quantity,
                    'expected_idr_total' => $request->expectedIdrTotal,
                    'expected_usd_total_micro' => $request->expectedUsdTotalMicro,
                ]);
            }, 1);
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (QueryException $error) {
            if (! in_array((string) $error->getCode(), ['55P03', '40P01'], true)) {
                throw $error;
            }

            return null;
        }
        if ($atCapacity) {
            throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Too many pending order claims. Retry later.', ['Retry-After' => '5']);
        }

        return $claim;
    }

    /**
     * One atomic conditional takeover of an expired lease: exactly one winner.
     *
     * The conditional `UPDATE` is executed under a short row-lock bound, so a
     * holder that still owns the unconditional claim lock (the financial commit
     * holds it through its commit) can only delay a takeover attempt for that
     * bound. A timed-out or deadlocked attempt is a lost takeover: it is never
     * retried past the caller's public deadline, never steals a live lease and
     * never surfaces as an error. The claim row is refreshed inside the same
     * transaction, so the winner's token cannot be replaced by a concurrent
     * takeover between the `UPDATE` and the read.
     */
    private function takeOver(CustomerApiPrincipal $principal, CustomerApiOrderClaim $claim, int $deadline): bool
    {
        if ($claim->lease_expires_at !== null && $claim->lease_expires_at->isFuture()) {
            return false;
        }
        $lockWait = (int) min(self::LOCK_WAIT_MILLISECONDS, ($deadline - hrtime(true)) / 1_000_000);
        if ($lockWait < 1) {
            return false;
        }
        $token = bin2hex(random_bytes(16));
        try {
            $updated = DB::transaction(function () use ($principal, $claim, $token, $deadline, $lockWait): int {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL lock_timeout = '.$lockWait);
                }
                Customer::query()->whereKey($principal->customerId)->sharedLock()->firstOrFail();
                $principal->revalidateForMutation();
                $remainingLockWait = (int) min(self::LOCK_WAIT_MILLISECONDS, ($deadline - hrtime(true)) / 1_000_000);
                if ($remainingLockWait < 1) {
                    return 0;
                }
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL lock_timeout = '.$remainingLockWait);
                }
                $updated = CustomerApiOrderClaim::query()->whereKey($claim->getKey())
                    ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
                    ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))
                    ->update(['attempt_token' => $token, 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now()]);
                if ($updated === 1) {
                    $claim->refresh();
                }

                return $updated;
            });
        } catch (QueryException $error) {
            if (! in_array((string) $error->getCode(), ['55P03', '40P01'], true)) {
                throw $error;
            }

            // The row lock outlived the bound (or a deadlock aborted us): the
            // attempt never won, so the caller keeps polling until its deadline.
            return false;
        }
        if ($updated !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Orphaned economic Orders remain recoverable and do not consume the
     * rejected-claim quota. PostgreSQL has built-in SHA-256 and indexed order
     * origin/checkout keys; SQLite still checks the hash before deletion.
     */
    private function withoutProtectedOrders(Builder $claims): Builder
    {
        $claims->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('orders')
            ->whereColumn('orders.api_order_claim_id', 'customer_api_order_claims.id'));
        if (DB::getDriverName() === 'pgsql') {
            $claims->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('orders')->whereRaw(
                "orders.checkout_key = 'api-' || substr(encode(sha256(convert_to('api-order:' || CAST(customer_api_order_claims.id AS text), 'UTF8')), 'hex'), 1, 60)",
            ));
        }

        return $claims;
    }

    private function find(int $customerId, string $key): ?CustomerApiOrderClaim
    {
        return CustomerApiOrderClaim::query()
            ->where('customer_id', $customerId)
            ->where('operation', CustomerApiOrderClaim::OPERATION_CREATE_ORDER)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function accountPreference(int $customerId): BalancePaymentPreference
    {
        $preference = Customer::query()->whereKey($customerId)->value('balance_payment_preference');
        if (! $preference instanceof BalancePaymentPreference) {
            throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.');
        }

        return $preference;
    }
}
