<?php

namespace App\Services\Admin;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendTelegramReceipt;
use App\Models\BscPaymentTransferEvidence;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\SupplierFulfillment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GuestOrderNotificationService;
use App\Services\InventoryLedger;
use App\Services\MoneyReceipts\ExternalMoneyReceiptReservations;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\OrderLifecycle;
use App\Services\ProductCredentialAllocator;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\Warranty\RemainderRefundService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ManualFulfillmentResolutionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryLedger $inventory,
        private readonly OrderLifecycle $orders,
        private readonly ProductCredentialAllocator $credentials,
        private readonly GuestOrderNotificationService $guestOrderNotifications,
        private readonly ExternalMoneyReceiptWriter $receipts,
    ) {}

    public function resolve(ManualFulfillmentReview $review, User $actor, string $action, string $reason, ?string $refundReference = null, ?string $refundResult = null, ?string $ip = null): ManualFulfillmentReview
    {
        return DB::transaction(function () use ($review, $actor, $action, $reason, $refundReference, $refundResult, $ip) {
            $initial = ManualFulfillmentReview::query()->findOrFail($review->id);
            $initialPayment = Payment::query()->findOrFail($initial->payment_id);
            if ($action === 'refund' && in_array($initialPayment->provider, ['store_balance', 'usdt_balance'], true)) {
                $customer = Order::findOrFail($initial->order_id)->customer;
                $ledger = $initialPayment->provider === 'usdt_balance' ? app(CustomerUsdtBalanceLedger::class) : app(StoreBalanceLedger::class);
                $ledger->lockForTransaction($customer);
            }
            Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $initial->order_id)->orderBy('id')->lockForUpdate()->get();
            app(SupplierFulfillmentService::class)->lockForReview(Order::findOrFail($initial->order_id));
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
        if ($review->reason === 'supplier_fulfillment') {
            $fulfillments = SupplierFulfillment::query()->where('order_id', $review->order_id)->where('state', 'review')->pluck('id');
            foreach ($fulfillments as $id) {
                app(SupplierFulfillmentService::class)->retryAfterTopup($id);
            }

            return $review->fresh();
        }
        $order = Order::query()->lockForUpdate()->findOrFail($review->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
        $isPendingBscReview = $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && strtolower((string) $payment->provider) === 'manual'
            && strtoupper((string) $payment->method) === 'USDT_BEP20';

        if ($isPendingBscReview) {
            if ($this->claimPendingBscReceipt($payment)->isHeld()) {
                throw new InvalidOrderTransition('The BSC payment receipt is held for review and cannot be completed.');
            }

            $completion = $this->orders->applyManualCompletion($order);
            if (! $completion->newlyApplied) {
                throw new InvalidOrderTransition('The BSC payment review could not be completed from its current state.');
            }
        } else {
            if ($payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid) {
                throw new InvalidOrderTransition('Only paid manual fulfillment reviews can be fulfilled.');
            }

            [$items, $movements, $products] = $this->inventory->orderInventoryState($order);
            if ($items->contains(fn ($item) => $item->supplier_quantity > 0)) {
                throw new InvalidOrderTransition('This order needs explicit manual delivery through the fulfillment review.');
            }
            $this->inventory->recordLatePaymentSales($order, $items, $products, $movements);
            foreach ($items as $item) {
                $product = $products[$item->product_id] ?? throw new InsufficientStock('Ordered product is no longer available.');
                $this->credentials->allocate($item, $product, $item->quantity);
            }
            $order->update(['order_status' => OrderStatus::Completed]);
            $this->guestOrderNotifications->record($order, GuestOrderNotificationEvent::FulfillmentCompleted);
        }

        $order->refresh();
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_FULFILLED, $actor, $reason);
        $this->audit->log('manual_fulfillment_review.fulfilled', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'order_status' => OrderStatus::Completed->value], $actor, $ip);
        if (! $isPendingBscReview) {
            SendTelegramReceipt::dispatch((string) $order->getKey())
                ->onQueue(TelegramOutboundEffectService::RECEIPT_QUEUE);
        }

        return $resolved;
    }

    /**
     * Owner-approved BSC evidence claims the chain receipt from the registry;
     * legacy reviews without persisted evidence fall back to a per-payment
     * owner attestation so no approval can move money without a claim.
     */
    private function claimPendingBscReceipt(Payment $payment): ExternalMoneyReceiptClaimOutcome
    {
        $evidence = BscPaymentTransferEvidence::query()
            ->where('payment_id', $payment->getKey())
            ->orderByDesc('id')
            ->first();

        if ($evidence === null) {
            return $this->receipts->claimOwnerAttestation($payment);
        }

        if ($evidence->log_index === null) {
            $this->receipts->reservePayment(
                $payment,
                ExternalMoneyReceiptReservation::REASON_MISSING_CHAIN_IDENTITY,
                ['tx_hash' => (string) $evidence->tx_hash],
            );

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        $detail = PaymentBscDetail::query()->whereKey($payment->getKey())->first();

        try {
            $receipt = $this->receipts->receiptForBscPayment(
                $payment,
                (int) ($detail?->chain_id ?: config('services.bsc.chain_id', 56)),
                (string) $evidence->tx_hash,
                (int) $evidence->log_index,
                (string) $evidence->receiver_address,
                (string) $evidence->amount_units,
                $detail?->token_decimals === null ? null : (int) $detail->token_decimals,
            );
        } catch (InvalidArgumentException $exception) {
            $this->receipts->reservePayment(
                $payment,
                ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY,
                ['identity_error' => $exception->getMessage()],
            );

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        return $this->receipts->claim(
            $receipt,
            ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT,
            (string) $payment->getKey(),
        );
    }

    private function refund(ManualFulfillmentReview $review, User $actor, string $reason, ?string $reference, ?string $result, ?string $ip): ManualFulfillmentReview
    {
        $order = Order::findOrFail($review->order_id);
        app(SupplierFulfillmentService::class)->assertRefundable($order);
        $payment = Payment::query()->findOrFail($review->payment_id);
        if (in_array($payment->provider, ['store_balance', 'usdt_balance'], true)) {
            $this->orders->applyPaymentRefunded($payment);

            return $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, null, 'success');
        }
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

        $remainder = app(RemainderRefundService::class)->registerConfirmedExternal($actor, $order, $payment, $reference);
        $this->orders->applyPaymentRefunded($payment);
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, $reference, $result);
        $this->audit->log('manual_fulfillment_review.refunded', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'amount' => $review->amount, 'currency' => $review->currency, 'provider_reference' => $reference, 'provider_result' => $result, 'remainder_refund_id' => $remainder?->id], $actor, $ip);

        return $resolved;
    }

    private function reject(ManualFulfillmentReview $review, User $actor, string $reason, ?string $ip): ManualFulfillmentReview
    {
        $order = Order::query()->lockForUpdate()->findOrFail($review->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
        if ($payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && strtolower((string) $payment->provider) === 'manual'
            && strtoupper((string) $payment->method) === 'USDT_BEP20') {
            $this->orders->applyPendingCancellation($order);
        }

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
