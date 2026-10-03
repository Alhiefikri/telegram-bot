<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\SupplierFulfillment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Support\Facades\DB;

/** Refunds only units proven not acquired after bounded fulfillment fails. */
final class MissingFulfillmentRefundService
{
    public function request(Customer $customer, int $orderItemId): OrderRefund
    {
        $item = OrderItem::findOrFail($orderItemId);
        if ((int) $item->order->customer_id !== (int) $customer->id) {
            throw new InvalidOrderTransition('Order remainder is unavailable.');
        }

        return DB::transaction(function () use ($customer, $item): OrderRefund {
            $ledger = app(StoreBalanceLedger::class);
            $ledger->lockForTransaction($customer);
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $fulfillment = app(SupplierFulfillmentService::class);
            $fulfillment->lockForReview($order);
            $f = SupplierFulfillment::query()->where('order_item_id', $item->id)->lockForUpdate()->firstOrFail();
            $existing = OrderRefund::query()->where('supplier_fulfillment_id', $f->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $payment = $payments->first(fn ($p) => $p->status === PaymentStatus::Paid && $p->paid_at !== null);
            if ((int) $order->customer_id !== (int) $customer->id || $payment === null || $order->payment_status !== PaymentStatus::Paid
                || $order->order_status !== OrderStatus::Processing || $f->state !== 'review'
                || $order->hasOpenLatePaymentReview() || $f->reserved_quantity !== 0) {
                throw new InvalidOrderTransition('Only a proven unfulfilled remainder can be refunded.');
            }
            $fulfillment->assertRefundable($order);
            $amount = self::entitlement($f, $payment->currency);
            $registered = (int) OrderRefund::query()->where('payment_id', $payment->id)->whereIn('state', ['requested', 'uncertain', 'confirmed'])->sum('amount');
            if ($amount <= 0 || $amount > RefundAllocation::remaining($payment->amount, $registered)) {
                throw new InvalidOrderTransition('Invalid missing-unit refund entitlement.');
            }
            $acquired = self::acquired($f);
            $refund = OrderRefund::create([
                'order_id' => $order->id, 'payment_id' => $payment->id, 'customer_id' => $customer->id,
                'supplier_fulfillment_id' => $f->id, 'missing_quantity' => $f->quantity - $acquired,
                'amount' => $amount, 'currency' => $payment->currency, 'state' => 'requested',
            ]);
            $f->update(['state' => 'refund_pending', 'next_attempt_at' => null, 'last_error' => 'missing_units_refund_pending']);
            if ($payment->provider === 'store_balance') {
                $ledger->refundPartial($customer, $refund, 'order-refund:'.$refund->id);
                $refund->update(['state' => 'confirmed', 'confirmed_at' => now()]);
                $fulfillment->completeMissingRefund($f, $refund);
            }
            app(AuditLogger::class)->log('supplier.missing_units_refund_requested', $refund, new: ['order_id' => $order->id, 'quantity' => $refund->missing_quantity, 'amount' => $amount, 'currency' => $payment->currency]);

            return $refund->fresh();
        }, 5);
    }

    public static function acquired(SupplierFulfillment $f): int
    {
        return ProductStockItem::query()->where('order_item_id', $f->order_item_id)
            ->whereHas('supplierPurchase', fn ($q) => $q->where('supplier_fulfillment_id', $f->id)->whereNull('warranty_claim_id'))->count();
    }

    public function confirmExternal(User $actor, OrderRefund $initial, int $amount, string $currency, string $reference, string $result): OrderRefund
    {
        $reference = trim($reference);
        if ($actor->role?->canReconcilePayments() !== true || ! in_array($result, ['success', 'failure', 'uncertain'], true)
            || trim($reference) === '' || strlen($reference) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new InvalidOrderTransition('Invalid external missing-unit refund evidence.');
        }

        return DB::transaction(function () use ($actor, $initial, $amount, $currency, $reference, $result): OrderRefund {
            $order = Order::query()->whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($initial->payment_id)->lockForUpdate()->firstOrFail();
            app(SupplierFulfillmentService::class)->lockForReview($order);
            $refund = OrderRefund::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $f = SupplierFulfillment::findOrFail($refund->supplier_fulfillment_id);
            $identity = hash('sha256', $payment->provider.'|'.$currency.'|'.$reference);
            if ($refund->amount !== $amount || $refund->currency !== $currency || $payment->provider === 'store_balance'
                || $refund->warranty_claim_id !== null || $f->order_id !== $order->id) {
                throw new InvalidOrderTransition('Transfer evidence does not match the missing-unit entitlement.');
            }
            if ($refund->state === 'confirmed') {
                if ($refund->external_reference_hash !== $identity || $result !== 'success') {
                    throw new InvalidOrderTransition('Transfer was already confirmed with different evidence.');
                }

                return $refund;
            }
            if ($f->state !== 'refund_pending' || $payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid
                || ! in_array($refund->state, ['requested', 'uncertain'], true)
                || ($refund->state === 'uncertain' && $refund->external_reference_hash !== $identity)
                || OrderRefund::query()->where('external_reference_hash', $identity)->whereKeyNot($refund->id)->exists()) {
                throw new InvalidOrderTransition('Recover the original transfer before another refund.');
            }
            if ($result === 'failure') {
                app(AuditLogger::class)->log('supplier.missing_units_transfer_failed', $refund, user: $actor);

                return $refund;
            }
            RefundAllocation::remaining($payment->amount, (int) OrderRefund::where('payment_id', $payment->id)->whereIn('state', ['requested', 'uncertain', 'confirmed'])->sum('amount'));
            $refund->update([
                'state' => $result === 'success' ? 'confirmed' : 'uncertain', 'external_reference' => $reference,
                'external_reference_hash' => $identity, 'actor_user_id' => $actor->id, 'confirmed_at' => $result === 'success' ? now() : null,
            ]);
            if ($result === 'success') {
                app(SupplierFulfillmentService::class)->completeMissingRefund($f, $refund);
            }
            app(AuditLogger::class)->log('supplier.missing_units_transfer_recorded', $refund, new: ['amount' => $amount, 'currency' => $currency, 'result' => $result], user: $actor);

            return $refund->fresh();
        }, 5);
    }

    public static function entitlement(SupplierFulfillment $f, string $currency): int
    {
        $item = $f->orderItem;
        $total = $currency === 'USDT' ? $item->usdt_subtotal : $item->subtotal;
        $acquired = self::acquired($f);
        $provenAcquired = (int) $f->purchases()->whereNull('warranty_claim_id')->whereNotNull('committed_at')->sum('observed_quantity');
        if ($total === null || $f->quantity <= $acquired || $provenAcquired !== $acquired) {
            throw new InvalidOrderTransition('There is no missing-unit entitlement.');
        }
        $amount = 0;
        for ($number = $item->localQuantity() + $acquired + 1; $number <= $item->quantity; $number++) {
            $amount += RefundAllocation::unit($total, $item->quantity, $number);
        }

        return $amount;
    }
}
