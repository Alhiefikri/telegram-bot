<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryLedger;
use App\Services\OrderLifecycle;
use App\Services\ProductCredentialAllocator;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;

class ManualFulfillmentResolutionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryLedger $inventory,
        private readonly OrderLifecycle $orders,
        private readonly ProductCredentialAllocator $credentials,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function resolve(ManualFulfillmentReview $review, User $actor, string $action, string $reason, ?string $refundReference = null, ?string $refundResult = null, ?string $ip = null): ManualFulfillmentReview
    {
        return DB::transaction(function () use ($review, $actor, $action, $reason, $refundReference, $refundResult, $ip) {
            $locked = ManualFulfillmentReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->state !== ManualFulfillmentReview::STATE_PENDING) {
                return $locked;
            }

            return match ($action) {
                'fulfill' => $this->fulfill($locked, $actor, $reason, $ip),
                'refund' => $this->refund($locked, $actor, $reason, $refundReference, $refundResult, $ip),
                'reject' => $this->reject($locked, $actor, $reason, $ip),
                default => throw new InvalidOrderTransition('Unknown manual fulfillment resolution.'),
            };
        }, attempts: 5);
    }

    private function fulfill(ManualFulfillmentReview $review, User $actor, string $reason, ?string $ip): ManualFulfillmentReview
    {
        $order = Order::query()->lockForUpdate()->findOrFail($review->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
        if ($payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid) {
            throw new InvalidOrderTransition('Only paid late-payment reviews can be fulfilled.');
        }

        [$items, $movements, $products] = $this->inventory->orderInventoryState($order);
        $this->inventory->recordLatePaymentSales($order, $items, $products, $movements);
        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? throw new InsufficientStock('Ordered product is no longer available.');
            $this->credentials->allocate($item, $product, $item->quantity);
        }
        $order->update(['order_status' => OrderStatus::Completed]);
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_FULFILLED, $actor, $reason);
        $this->audit->log('manual_fulfillment_review.fulfilled', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'order_status' => OrderStatus::Completed->value], $actor, $ip);
        $effect = $this->outboundEffects->recordReceipt($order);
        if ($effect !== null) {
            $this->dispatcher->dispatch($effect);
        }

        return $resolved;
    }

    private function refund(ManualFulfillmentReview $review, User $actor, string $reason, ?string $reference, ?string $result, ?string $ip): ManualFulfillmentReview
    {
        if (! filled($reference) || ! in_array($result, ['success', 'failure', 'uncertain'], true)) {
            throw new InvalidOrderTransition('A provider reference and explicit refund result are required.');
        }
        if ($result === 'failure') {
            $this->audit->log('manual_fulfillment_review.refund_failed', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'provider_reference' => $reference, 'provider_result' => $result], $actor, $ip);
            throw new InvalidOrderTransition('The provider reported the refund failed; the review remains pending.');
        }
        if ($result === 'uncertain') {
            return $this->markRefundUncertain($review, $actor, $reason, $reference, $ip);
        }

        $payment = Payment::query()->findOrFail($review->payment_id);
        $this->orders->applyPaymentRefunded($payment);
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, $reference, $result);
        $this->audit->log('manual_fulfillment_review.refunded', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'amount' => $review->amount, 'currency' => $review->currency, 'provider_reference' => $reference, 'provider_result' => $result], $actor, $ip);

        return $resolved;
    }

    private function reject(ManualFulfillmentReview $review, User $actor, string $reason, ?string $ip): ManualFulfillmentReview
    {
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REJECTED, $actor, $reason);
        $this->audit->log('manual_fulfillment_review.rejected', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason], $actor, $ip);

        return $resolved;
    }

    private function markRefundUncertain(ManualFulfillmentReview $review, User $actor, string $reason, string $reference, ?string $ip): ManualFulfillmentReview
    {
        $review->update(['state' => ManualFulfillmentReview::STATE_REFUND_UNCERTAIN, 'resolution_reason' => $reason, 'refund_provider_reference' => $reference, 'refund_provider_result' => 'uncertain', 'refund_requested_at' => now(), 'actor_user_id' => $actor->id, 'resolved_at' => now()]);
        $this->audit->log('manual_fulfillment_review.refund_uncertain', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'provider_reference' => $reference], $actor, $ip);

        return $review->fresh();
    }

    private function markResolved(ManualFulfillmentReview $review, string $state, User $actor, string $reason, ?string $refundReference = null, ?string $refundResult = null): ManualFulfillmentReview
    {
        $review->update(['state' => $state, 'resolution_reason' => $reason, 'refund_provider_reference' => $refundReference, 'refund_provider_result' => $refundResult, 'refund_requested_at' => $refundReference ? now() : null, 'actor_user_id' => $actor->id, 'resolved_at' => now()]);

        return $review->fresh();
    }
}
