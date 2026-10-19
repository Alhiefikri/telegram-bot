<?php

namespace App\Services\StoreBalance;

use App\Enums\PaymentStatus;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\SupplierFulfillment;
use App\Models\WarrantyClaim;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantyClaimService;

/** Uses the original registered entitlement, never an independent adjustment source. */
final class BalanceAdjustmentRefundRecovery
{
    public function __construct(private readonly StoreBalanceLedger $ledger) {}

    public function apply(Customer $customer, BalanceAdjustmentRequest $request, ApprovedBalanceAdjustment $approval): StoreBalanceMutationResult
    {
        $initial = OrderRefund::findOrFail($request->source_id);
        $claim = null;
        $stock = null;
        $fulfillment = null;
        if ($initial->warranty_claim_id !== null) {
            [$claim, $order, $unit, $stock] = app(WarrantyClaimService::class)->lockForSettlement(WarrantyClaim::findOrFail($initial->warranty_claim_id));
            if ($claim->state !== 'approved' || $claim->verified_at === null || $claim->settlement_choice !== 'refund'
                || $claim->settlement_state !== 'refund_pending' || $unit->retail_currency !== 'IDR' || $unit->retail_amount !== $request->amount
                || (int) $initial->supplier_account_unit_id !== (int) $unit->id || (int) $claim->customer_id !== (int) $customer->id) {
                throw new BalanceAdjustmentRejected('refund_entitlement_unavailable');
            }
        } elseif ($initial->supplier_fulfillment_id !== null) {
            $order = Order::query()->whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            app(SupplierFulfillmentService::class)->lockForReview($order);
            $fulfillment = SupplierFulfillment::query()->whereKey($initial->supplier_fulfillment_id)->lockForUpdate()->firstOrFail();
            if ($fulfillment->state !== 'refund_pending' || $fulfillment->reserved_quantity !== 0
                || $fulfillment->order_id !== $order->id
                || $initial->missing_quantity !== $fulfillment->quantity - MissingFulfillmentRefundService::acquired($fulfillment)
                || MissingFulfillmentRefundService::entitlement($fulfillment, 'IDR') !== $request->amount) {
                throw new BalanceAdjustmentRejected('refund_entitlement_unavailable');
            }
            app(SupplierFulfillmentService::class)->assertRefundable($order);
        } else {
            throw new BalanceAdjustmentRejected('refund_entitlement_unavailable');
        }
        $refund = OrderRefund::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::findOrFail($refund->payment_id);
        if ($refund->state !== 'requested' || $refund->external_reference_hash !== null || $refund->currency !== 'IDR'
            || $refund->amount !== $request->amount || (int) $refund->customer_id !== (int) $customer->id
            || (int) $order->customer_id !== (int) $customer->id || $payment->currency !== 'IDR'
            || $payment->order_id !== $order->id || $payment->status !== PaymentStatus::Paid || $payment->paid_at === null
            || $order->payment_status !== PaymentStatus::Paid || $order->hasOpenLatePaymentReview()) {
            throw new BalanceAdjustmentRejected('refund_resolution_unavailable');
        }
        $entitlements = OrderRefund::query()->where('payment_id', $payment->id)->whereIn('state', ['requested', 'uncertain', 'confirmed'])->get(['amount']);
        $remaining = $payment->amount;
        foreach ($entitlements as $entitlement) {
            if ($entitlement->amount > $remaining) {
                throw new BalanceAdjustmentRejected('refund_exceeds_original_payment');
            }
            $remaining -= $entitlement->amount;
        }
        $mutation = $payment->provider === 'store_balance'
            ? $this->ledger->refundPartial($customer, $refund, 'order-refund:'.$refund->id)
            : $this->ledger->creditApprovedRefundAdjustment($request, $refund, $approval);
        $refund->update(['state' => 'confirmed', 'confirmed_at' => now(), 'actor_user_id' => $request->owner_user_id]);
        if ($claim !== null) {
            $claim->update(['settlement_state' => 'refunded']);
            $stock->update(['retired_at' => now(), 'retired_reason' => 'warranty_refund']);
            if ((int) OrderRefund::query()->where('payment_id', $payment->id)->where('state', 'confirmed')->sum('amount') === $payment->amount) {
                app(OrderLifecycle::class)->applyPaymentRefunded($payment);
            }
        } else {
            app(SupplierFulfillmentService::class)->completeMissingRefund($fulfillment, $refund);
        }

        return $mutation;
    }
}
