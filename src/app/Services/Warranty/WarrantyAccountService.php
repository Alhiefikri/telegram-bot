<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessWarrantyConfirmation;
use App\Models\Customer;
use App\Models\SupplierAccountUnit;
use App\Models\WarrantyClaim;
use App\Services\AuditLogger;
use App\Services\Suppliers\BuyMailClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Explicit confirmation closes supplier warranty; delivery never invokes it. */
final class WarrantyAccountService
{
    public function __construct(private readonly WarrantyClaimService $claims, private readonly BuyMailClient $client, private readonly AuditLogger $audit) {}

    public function confirm(Customer $customer, string $unitPublicId): SupplierAccountUnit
    {
        $initial = SupplierAccountUnit::query()->where('public_id', $unitPublicId)->firstOrFail();
        if ((int) $initial->order->customer_id !== (int) $customer->id) {
            throw new InvalidOrderTransition('Account confirmation is unavailable.');
        }
        $unit = DB::transaction(function () use ($customer, $initial): SupplierAccountUnit {
            [$order, $unit, $stock] = $this->claims->lockAccountUnit($initial);
            if ((int) $order->customer_id !== (int) $customer->id) {
                throw new InvalidOrderTransition('Account confirmation is unavailable.');
            }
            if ($unit->confirmation_state !== null) {
                return $unit;
            }
            if ($order->payment_status !== PaymentStatus::Paid || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                || $stock->retired_at !== null || $unit->provider_state !== 'active' || ! $unit->refund_deadline->isFuture()
                || WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->exists()) {
                throw new InvalidOrderTransition('Account confirmation is unavailable.');
            }
            $unit->update(['confirmation_state' => 'requested', 'confirmation_requested_at' => now(), 'confirmation_next_attempt_at' => now()]);
            $this->audit->log('warranty.account_confirmation_requested', $unit, new: ['unit_id' => $unit->id]);
            DB::afterCommit(fn () => ProcessWarrantyConfirmation::dispatch($unit->id));

            return $unit;
        }, 5);
        $this->process($unit->id);

        return $unit->fresh();
    }

    public function process(int $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('Confirmation transport cannot run under financial locks.');
        }
        $request = DB::transaction(function () use ($id): ?array {
            $initial = SupplierAccountUnit::find($id);
            if ($initial === null) {
                return null;
            }
            [$order, $unit, $stock, $supplier] = $this->claims->lockAccountUnit($initial);
            if (! in_array($unit->confirmation_state, ['requested', 'sending', 'unknown'], true)
                || $unit->confirmation_lease_until?->isFuture() || $unit->confirmation_next_attempt_at?->isFuture()) {
                return null;
            }
            $read = $unit->confirmation_state !== 'requested';
            if (! $read && ($order->payment_status !== PaymentStatus::Paid || $stock->retired_at !== null
                || WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->exists())) {
                $unit->update(['confirmation_state' => 'review', 'confirmation_next_attempt_at' => null]);

                return null;
            }
            $token = (string) Str::uuid();
            $unit->update(['confirmation_state' => 'sending', 'confirmation_token' => $token, 'confirmation_lease_until' => now()->addMinutes(3)]);

            return [$unit, $supplier, $token, $read];
        }, 5);
        if ($request === null) {
            return;
        }
        [$unit, $supplier, $token, $read] = $request;
        $account = null;
        try {
            $account = $read ? $this->client->account($supplier, $unit->external_id, $unit->purchase->supplier_order_id)
                : $this->client->confirmAccount($supplier, $unit->external_id, $unit->purchase->supplier_order_id);
        } catch (Throwable) {
            // Read-only recovery resolves a lost confirmation; no blind second POST.
        }
        DB::transaction(function () use ($unit, $token, $account): void {
            [, $locked] = $this->claims->lockAccountUnit($unit);
            if ($locked->confirmation_token !== $token) {
                return;
            }
            $locked->forceFill(['confirmation_token' => null, 'confirmation_lease_until' => null]);
            if ($account === null || $account['price'] !== $locked->cost_idr) {
                $locked->forceFill(['confirmation_state' => 'unknown', 'confirmation_last_error' => 'confirmation_outcome_unknown', 'confirmation_next_attempt_at' => now()->addMinute()])->save();

                return;
            }
            $confirmed = $account['status'] === 'completed' && $account['refund_status'] === null;
            $locked->forceFill([
                'provider_state' => $account['status'], 'confirmation_state' => $confirmed ? 'confirmed' : 'review',
                'confirmation_last_error' => $confirmed ? null : 'confirmation_review_required', 'confirmation_next_attempt_at' => null,
            ])->save();
        }, 5);
    }
}
