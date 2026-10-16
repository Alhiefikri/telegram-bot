<?php

namespace App\Services\CustomerApi;

use App\Enums\BalancePaymentPreference;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the durable create-order claim keyed by Customer + operation +
 * Idempotency-Key. The claim is inserted with database uniqueness as the only
 * guard, carries a canonical payload fingerprint, the admitted credential id
 * and the resolved preference snapshot, and is either `in_progress` under a
 * fenced lease or `accepted` against exactly one Order. Successful claims are
 * never pruned and never expire; an abandoned pre-order claim is recoverable
 * through one atomic conditional takeover of an expired lease.
 */
final class CustomerApiOrderClaims
{
    /** Pre-order lease length. Successful claims never expire. */
    public const LEASE_SECONDS = 30;

    /** Bounded wait for a concurrent identical request before answering in-progress. */
    public const WAIT_SECONDS = 2;

    private const POLL_MICROSECONDS = 100_000;

    public function acquire(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request): CustomerApiOrderAcquisition
    {
        $fingerprint = $request->fingerprint();
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (microtime(true) < $deadline) {
            $existing = $this->find($principal->customerId, $request->idempotencyKey);
            if ($existing === null) {
                if (! Product::withTrashed()->whereKey($request->productId)->exists()) {
                    throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
                }
                $claim = $this->insert($principal, $request, $fingerprint);
                if ($claim !== null) {
                    return CustomerApiOrderAcquisition::leased($claim);
                }

                continue;
            }
            if (! hash_equals($existing->fingerprint, $fingerprint)) {
                throw new CustomerApiFailure(409, 'idempotency_conflict', 'This Idempotency-Key was already used with a different request body.');
            }
            if ($existing->state === CustomerApiOrderClaim::STATE_ACCEPTED) {
                return CustomerApiOrderAcquisition::accepted((string) $existing->order_id);
            }
            if ($this->takeOver($existing)) {
                return CustomerApiOrderAcquisition::leased($existing);
            }
            usleep(self::POLL_MICROSECONDS);
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
    public function abandon(CustomerApiOrderClaim $claim): void
    {
        try {
            CustomerApiOrderClaim::query()->whereKey($claim->getKey())
                ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
                ->where('attempt_token', $claim->attempt_token)
                ->update(['attempt_token' => null, 'lease_expires_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            // A lost lease is bounded by its expiry; never mask the original failure.
        }
    }

    private function insert(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request, string $fingerprint): ?CustomerApiOrderClaim
    {
        $preference = $request->preferenceOverride ?? $this->accountPreference($principal->customerId);
        try {
            // Savepoint-scoped so a uniqueness race never aborts the caller.
            return DB::transaction(fn (): CustomerApiOrderClaim => CustomerApiOrderClaim::create([
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
            ]), 1);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** One atomic conditional takeover of an expired lease: exactly one winner. */
    private function takeOver(CustomerApiOrderClaim $claim): bool
    {
        if ($claim->lease_expires_at !== null && $claim->lease_expires_at->isFuture()) {
            return false;
        }
        $token = bin2hex(random_bytes(16));
        $updated = CustomerApiOrderClaim::query()->whereKey($claim->getKey())
            ->where('state', CustomerApiOrderClaim::STATE_IN_PROGRESS)
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))
            ->update(['attempt_token' => $token, 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now()]);
        if ($updated !== 1) {
            return false;
        }
        $claim->refresh();

        return true;
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
