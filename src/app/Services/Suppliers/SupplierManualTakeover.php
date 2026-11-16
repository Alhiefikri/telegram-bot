<?php

namespace App\Services\Suppliers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierManualHandoff;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SupplierManualTakeover
{
    public function __construct(
        private readonly SupplierManualTakeoverNotice $notice,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly AuditLogger $audit,
    ) {}

    public function takeOver(int $fulfillmentId, User $actor): void
    {
        if ($actor->role !== UserRole::Owner) {
            throw new InvalidOrderTransition('Only the Owner may take over supplier fulfillment.');
        }

        DB::transaction(function () use ($fulfillmentId, $actor): void {
            $initial = SupplierFulfillment::query()->findOrFail($fulfillmentId);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $fulfillments = SupplierFulfillment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $fulfillment = $fulfillments->firstWhere('id', $fulfillmentId);
            $purchases = SupplierPurchase::query()->where('supplier_fulfillment_id', $fulfillmentId)->orderBy('id')->lockForUpdate()->get();

            if ($fulfillment->manual_takeover_at !== null) {
                return;
            }
            $payment = $payments->first(fn (Payment $payment) => $payment->status === PaymentStatus::Paid && $payment->paid_at !== null && $payment->manual_alternative_at === null);
            if ($order->payment_status !== PaymentStatus::Paid || $order->order_status !== OrderStatus::Processing || $payment === null
                || ! in_array($fulfillment->state, ['pending', 'purchasing', 'awaiting_delivery', 'review'], true)
                || (int) $fulfillment->refunded_quantity > 0 || $fulfillment->delivered_at !== null) {
                throw new InvalidOrderTransition('This fulfillment is not eligible for manual takeover.');
            }
            $allocated = ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)
                ->whereHas('supplierPurchase', fn ($query) => $query->where('supplier_fulfillment_id', $fulfillmentId)->whereNull('warranty_claim_id'))
                ->count();
            $handedOff = (int) SupplierManualHandoff::query()->where('supplier_fulfillment_id', $fulfillmentId)->sum('quantity');
            if ($allocated + $handedOff + (int) $fulfillment->refunded_quantity >= (int) $fulfillment->quantity) {
                throw new InvalidOrderTransition('No units remain for manual takeover.');
            }
            foreach ($purchases as $purchase) {
                if ($purchase->historyReconciliations()->where('error_code', 'scan_in_progress')->where('requested_at', '>', now()->subMinutes(3))->exists()) {
                    throw new InvalidOrderTransition('Supplier history work is still in flight.');
                }
                if ($purchase->lease_expires_at?->isFuture() || $purchase->claim_token !== null || $purchase->state === 'sending') {
                    throw new InvalidOrderTransition('Supplier work is still in flight or requires reconciliation before takeover.');
                }
            }

            // Leave supplier reservations and economic evidence untouched, including ambiguous debits.
            $fulfillment->forceFill([
                'manual_takeover_at' => now(), 'manual_takeover_by' => $actor->id,
                'state' => 'review', 'next_attempt_at' => null,
            ])->save();
            $review = ManualFulfillmentReview::query()->firstOrCreate(['payment_id' => $payment->id], [
                'order_id' => $order->id, 'amount' => $payment->amount, 'currency' => $payment->currency,
                'reason' => 'supplier_fulfillment', 'state' => ManualFulfillmentReview::STATE_PENDING,
            ]);
            if ($review->state === ManualFulfillmentReview::STATE_FULFILLED
                && in_array($review->reason, [ManualFulfillmentReview::REASON_UNIQUE_EMAIL_UNAVAILABLE, ManualFulfillmentReview::REASON_MANUAL_UNLIMITED], true)) {
                $review->update([
                    'reason' => 'supplier_fulfillment', 'state' => ManualFulfillmentReview::STATE_PENDING,
                    'resolution_reason' => null, 'actor_user_id' => null, 'resolved_at' => null,
                ]);
            }
            $effect = $this->notice->record($fulfillment, $order);
            $this->audit->log('supplier_fulfillment.manual_takeover', $fulfillment, null, [
                'order_id' => $order->id, 'state' => 'review',
            ], $actor);
            DB::afterCommit(function () use ($effect): void {
                try {
                    $this->dispatcher->dispatch($effect);
                } catch (Throwable $error) {
                    Log::warning('supplier.manual_takeover_notice_dispatch_failed', ['effect_id' => $effect->id, 'exception' => $error::class]);
                }
            });
        }, 5);
    }
}
