<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessWarrantyReplacement;
use App\Models\Customer;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\AuditLogger;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtOrderRefundService;
use Illuminate\Support\Facades\DB;

/** Customer settlement is independent of Supplier Balance recovery. */
final class WarrantySettlementService
{
    public function __construct(private readonly WarrantyClaimService $claims, private readonly StoreBalanceLedger $ledger, private readonly AuditLogger $audit) {}

    public function choose(Customer $customer, string $claimPublicId, string $choice): WarrantyClaim
    {
        if (! in_array($choice, ['refund', 'replacement'], true)) {
            throw new InvalidOrderTransition('Invalid warranty settlement.');
        }
        $initial = WarrantyClaim::query()->where('public_id', $claimPublicId)
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customer->id))->firstOrFail();

        return DB::transaction(function () use ($customer, $initial, $choice): WarrantyClaim {
            // Account before Order, matching checkout and whole-order refunds.
            if ($initial->order->payments()->where('provider', 'usdt_balance')->exists()) {
                app(CustomerUsdtBalanceLedger::class)->lockForTransaction($customer);
            } else {
                $this->ledger->lockForTransaction($customer);
            }
            [$claim, $order, $unit, $stock] = $this->claims->lockForSettlement($initial);
            if ((int) $order->customer_id !== (int) $customer->id) {
                throw new InvalidOrderTransition('The claim is not owned by this Customer.');
            }
            $switchingSafeReplacement = false;
            if ($claim->settlement_choice !== null) {
                if ($claim->settlement_choice !== $choice) {
                    $attempt = SupplierPurchase::query()->where('warranty_claim_id', $claim->id)->lockForUpdate()->first();
                    $noReplacementDebit = $attempt === null || (! $attempt->warranty_hold_active && ! $attempt->lease_expires_at?->isFuture()
                        && ($attempt->state === 'rejected' || ($attempt->state === 'settled' && $attempt->observed_quantity === 0 && $attempt->actual_cost_micros === 0)));
                    if ($choice !== 'refund' || $claim->settlement_choice !== 'replacement'
                        || ! in_array($claim->settlement_state, ['replacement_pending', 'replacement_failed'], true) || ! $noReplacementDebit) {
                        throw new InvalidOrderTransition('This claim already has a different settlement.');
                    }
                    $switchingSafeReplacement = true;
                    $claim->update(['settlement_choice' => null, 'settlement_state' => 'awaiting_choice', 'replacement_next_attempt_at' => null]);
                } else {
                    return $claim;
                }
            }
            $payment = Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Paid)->whereNotNull('paid_at')->first();
            if ((int) $claim->customer_id !== (int) $customer->id) {
                if (in_array($payment?->provider, ['store_balance', 'usdt_balance'], true)) {
                    throw new InvalidOrderTransition('Store Balance refund must retain its original debit owner.');
                }
                $oldCustomerId = $claim->customer_id;
                $claim->update(['customer_id' => $customer->id]);
                $this->audit->log('warranty.guest_claim_linked', $claim, old: ['customer_id' => $oldCustomerId], new: ['customer_id' => $customer->id]);
            }
            if ($payment === null || $order->payment_status !== PaymentStatus::Paid
                || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                || $claim->state !== 'approved' || $claim->verified_at === null
                || $claim->settlement_state !== 'awaiting_choice' || ($stock->retired_at !== null && ! $switchingSafeReplacement)
                || $unit->retail_currency !== $payment->currency || $unit->retail_amount <= 0) {
                throw new InvalidOrderTransition('This claim is not eligible for settlement.');
            }
            if ($choice === 'replacement') {
                $claim->update(['settlement_choice' => 'replacement', 'settlement_state' => 'replacement_pending', 'replacement_next_attempt_at' => now()]);
                $stock->update(['retired_at' => now(), 'retired_reason' => 'warranty_replacement']);
                $this->audit->log('warranty.replacement_selected', $claim, new: ['unit_id' => $unit->id]);
                app(WarrantyNotificationPipeline::class)->record($claim);
                DB::afterCommit(fn () => ProcessWarrantyReplacement::dispatch($claim->id));

                return $claim->fresh();
            }
            $registered = (int) OrderRefund::query()->where('payment_id', $payment->id)->whereIn('state', ['requested', 'uncertain', 'confirmed'])->sum('amount');
            if ($unit->retail_amount > RefundAllocation::remaining($payment->amount, $registered)) {
                throw new InvalidOrderTransition('Refund exceeds remaining buyer entitlement.');
            }
            $claim->update(['settlement_choice' => 'refund', 'settlement_state' => 'refund_pending']);
            $refund = OrderRefund::firstOrCreate(['warranty_claim_id' => $claim->id], [
                'order_id' => $order->id, 'payment_id' => $payment->id, 'customer_id' => $customer->id,
                'supplier_account_unit_id' => $unit->id, 'amount' => $unit->retail_amount,
                'currency' => $unit->retail_currency, 'state' => 'requested',
            ]);
            if (in_array($payment->provider, ['store_balance', 'usdt_balance'], true)) {
                if ($payment->provider === 'usdt_balance') {
                    app(UsdtOrderRefundService::class)->partial($customer, $refund);
                } else {
                    $this->ledger->refundPartial($customer, $refund, 'order-refund:'.$refund->id);
                }
                $refund->update(['state' => 'confirmed', 'confirmed_at' => now()]);
                $claim->update(['settlement_state' => 'refunded']);
                $stock->update(['retired_at' => now(), 'retired_reason' => 'warranty_refund']);
                if ((int) OrderRefund::query()->where('payment_id', $payment->id)->where('state', 'confirmed')->sum('amount') === $payment->amount) {
                    app(OrderLifecycle::class)->applyPaymentRefunded($payment);
                }
            }
            $this->audit->log('warranty.refund_selected', $claim, new: ['refund_id' => $refund->id, 'amount' => $refund->amount, 'currency' => $refund->currency]);
            app(WarrantyNotificationPipeline::class)->record($claim);

            return $claim->fresh();
        }, 5);
    }

    public function confirmExternalRefund(User $actor, string $refundId, int $amount, string $currency, string $reference, string $result): OrderRefund
    {
        $reference = trim($reference);
        if ($actor->role?->canReconcilePayments() !== true || ! in_array($result, ['success', 'failure', 'uncertain'], true)
            || trim($reference) === '' || strlen($reference) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new InvalidOrderTransition('Invalid external refund evidence.');
        }
        $initial = OrderRefund::query()->findOrFail($refundId);
        if ($initial->warranty_claim_id === null && $initial->supplier_fulfillment_id === null) {
            throw new InvalidOrderTransition('This refund has no confirmable claim or fulfillment entitlement.');
        }
        if ($initial->warranty_claim_id === null && $initial->supplier_fulfillment_id !== null) {
            return app(MissingFulfillmentRefundService::class)->confirmExternal($actor, $initial, $amount, $currency, $reference, $result);
        }
        $initialClaim = WarrantyClaim::query()->findOrFail($initial->warranty_claim_id);

        return DB::transaction(function () use ($actor, $initial, $initialClaim, $amount, $currency, $reference, $result): OrderRefund {
            [$claim, $order, $unit, $stock] = $this->claims->lockForSettlement($initialClaim);
            $refund = OrderRefund::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($refund->payment_id)->firstOrFail();
            if (in_array($payment->provider, ['store_balance', 'usdt_balance'], true) || $refund->amount !== $amount || $refund->currency !== $currency
                || $claim->settlement_choice !== 'refund' || $refund->order_id !== $order->id
                || (int) $refund->supplier_account_unit_id !== (int) $unit->id) {
                throw new InvalidOrderTransition('External refund does not match the registered entitlement.');
            }
            $identity = hash('sha256', $payment->provider.'|'.$currency.'|'.$reference);
            if ($refund->state === 'confirmed') {
                if ($refund->external_reference_hash !== $identity || $result !== 'success') {
                    throw new InvalidOrderTransition('The external refund was already confirmed with different evidence.');
                }

                return $refund;
            }
            if (! in_array($claim->settlement_state, ['refund_pending', 'refund_uncertain'], true)
                || $payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid
                || OrderRefund::query()->where('external_reference_hash', $identity)->whereKeyNot($refund->id)->exists()) {
                throw new InvalidOrderTransition('External refund evidence is unavailable or already claimed.');
            }
            if ($result === 'failure') {
                $this->audit->log('warranty.external_refund_failed', $claim, new: ['refund_id' => $refund->id], user: $actor);

                return $refund;
            }
            if ($refund->state === 'uncertain' && $refund->external_reference_hash !== $identity) {
                throw new InvalidOrderTransition('Recover the original uncertain transfer before using another reference.');
            }
            $registered = (int) OrderRefund::query()->where('payment_id', $payment->id)->whereIn('state', ['requested', 'uncertain', 'confirmed'])->sum('amount');
            RefundAllocation::remaining($payment->amount, $registered);
            $refund->update([
                'state' => $result === 'success' ? 'confirmed' : 'uncertain',
                'external_reference' => $reference, 'external_reference_hash' => $identity,
                'actor_user_id' => $actor->id, 'confirmed_at' => $result === 'success' ? now() : null,
            ]);
            $claim->update(['settlement_state' => $result === 'success' ? 'refunded' : 'refund_uncertain']);
            if ($result === 'success') {
                $stock->update(['retired_at' => $stock->retired_at ?? now(), 'retired_reason' => 'warranty_refund']);
                if ((int) OrderRefund::query()->where('payment_id', $payment->id)->where('state', 'confirmed')->sum('amount') === $payment->amount) {
                    app(OrderLifecycle::class)->applyPaymentRefunded($payment);
                }
            }
            $this->audit->log('warranty.external_refund_recorded', $claim, new: ['refund_id' => $refund->id, 'amount' => $amount, 'currency' => $currency, 'result' => $result], user: $actor);
            app(WarrantyNotificationPipeline::class)->record($claim);

            return $refund->fresh();
        }, 5);
    }
}
