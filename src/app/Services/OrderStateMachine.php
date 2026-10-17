<?php

namespace App\Services;

use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\DeleteAdminPendingOrderNotification;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\SupplierFulfillment;
use App\Services\Bsc\BscConfirmationGrace;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\SalesProofEligibility;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtOrderRefundService;
use App\Support\PaymentProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderStateMachine implements OrderLifecycle
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly ?ProductCredentialAllocator $credentials = null,
        private readonly ?ProviderCancellationAttempts $providerCancellationAttempts = null,
        private readonly ?TelegramOutboundEffectService $outboundEffects = null,
        private readonly ?TelegramOutboundEffectDispatcher $outboundDispatcher = null,
        private readonly ?BscConfirmationGrace $bscConfirmationGrace = null,
        private readonly ?GuestOrderNotificationService $guestOrderNotifications = null,
        private readonly ?StoreBalanceLedger $storeBalance = null,
        private readonly ?OrderCredentialMailService $credentialMails = null,
    ) {}

    public function markPaymentPaid(Payment $payment): Order
    {
        return $this->applyPaymentPaid($payment)->order;
    }

    public function applyPaymentPaid(Payment $payment): OrderLifecycleResult
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment): OrderLifecycleResult {
                $lockedPayment = $payments->firstWhere('id', $payment->id);
                if (! $lockedPayment) {
                    throw new InvalidOrderTransition('Payment is not attached to order.');
                }

                return $this->applyPaidTransition($lockedPayment, $order);
            });
        }, 5);
    }

    public function completeManualOrder(Order $order): Order
    {
        return $this->applyManualCompletion($order)->order;
    }

    public function applyManualCompletion(Order $order): OrderLifecycleResult
    {
        return DB::transaction(function () use ($order): OrderLifecycleResult {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): OrderLifecycleResult {
                $manualPayment = $payments->where('provider', 'manual')->first();

                if ($lockedOrder->order_status === OrderStatus::Completed
                    && $lockedOrder->payment_status === PaymentStatus::Paid
                    && $manualPayment?->status === PaymentStatus::Paid) {
                    $completed = $lockedOrder->fresh(['payments']);

                    return new OrderLifecycleResult(
                        order: $completed,
                        payment: $completed->payments->firstWhere('id', $manualPayment->id),
                        newlyApplied: false,
                    );
                }

                if ($lockedOrder->order_status === OrderStatus::Processing
                    && $lockedOrder->payment_status === PaymentStatus::Paid
                    && $manualPayment?->status === PaymentStatus::Paid) {
                    return $this->applyPaidTransition($manualPayment, $lockedOrder);
                }

                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be completed.');
                }

                $paidOrder = $this->applyPaidTransition($payment, $lockedOrder);
                if ($paidOrder->orderStatus() !== OrderStatus::Processing || $paidOrder->paymentStatus() !== PaymentStatus::Paid || ! $paidOrder->newlyApplied) {
                    throw new InvalidOrderTransition('Only newly paid manual orders can be completed.');
                }

                $lockedOrder->refresh();
                if ($this->supplierFulfillment()->hasPending($lockedOrder)) {
                    return $paidOrder;
                }
                $lockedOrder->update(['order_status' => OrderStatus::Completed]);
                $this->recordGuestNotification($lockedOrder, GuestOrderNotificationEvent::FulfillmentCompleted);
                $this->credentialMails()->record($lockedOrder);

                $completed = $lockedOrder->fresh(['payments']);

                return new OrderLifecycleResult(
                    order: $completed,
                    payment: $completed->payments->firstWhere('id', $payment->id),
                    newlyApplied: true,
                    receiptEligible: true,
                    salesProofEligible: $paidOrder->salesProofEligible,
                );
            });
        }, 5);
    }

    public function rejectManualOrder(Order $order): Order
    {
        return $this->applyManualRejection($order)->order;
    }

    public function applyManualRejection(Order $order): OrderLifecycleResult
    {
        return DB::transaction(function () use ($order): OrderLifecycleResult {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): OrderLifecycleResult {
                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be rejected.');
                }

                return $this->applyReleaseTransition($lockedOrder, $payments, OrderStatus::Cancelled, PaymentStatus::Failed, false);
            });
        }, 5);
    }

    public function markPaymentRefunded(Payment $payment): Order
    {
        return $this->applyPaymentRefunded($payment)->order;
    }

    public function applyPaymentRefunded(Payment $payment): OrderLifecycleResult
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);
            $storeBalanceCustomerId = $this->lockStoreBalanceAccount($initial);

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment, $storeBalanceCustomerId): OrderLifecycleResult {
                $lockedPayment = $payments->firstWhere('id', $payment->id);
                if (! $lockedPayment) {
                    throw new InvalidOrderTransition('Payment is not attached to order.');
                }
                if ($lockedPayment->provider === PaymentProvider::USDT_BALANCE) {
                    $customer = Customer::findOrFail($storeBalanceCustomerId);
                    app(UsdtOrderRefundService::class)->full($customer, $order, $lockedPayment);
                }

                $this->supplierFulfillment()->assertRefundable($order);
                if (! in_array($lockedPayment->provider, [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)
                    && OrderRefund::query()->where('payment_id', $lockedPayment->id)->exists()
                    && (int) OrderRefund::query()->where('payment_id', $lockedPayment->id)->where('state', 'confirmed')->sum('amount') !== $lockedPayment->amount) {
                    throw new InvalidOrderTransition('Confirm or recover every registered external refund before refunding the full Order.');
                }
                $this->refundStoreBalance($order, $lockedPayment, $storeBalanceCustomerId);
                $wasAlreadyRefunded = $lockedPayment->status === PaymentStatus::Refunded
                    && $order->payment_status === PaymentStatus::Refunded;

                $wasAlreadyCancelled = $order->order_status === OrderStatus::Cancelled;
                if (! $wasAlreadyRefunded) {
                    $this->releaseRefundedOrderResources($order);
                }

                if ($lockedPayment->status !== PaymentStatus::Refunded) {
                    $lockedPayment->update(['status' => PaymentStatus::Refunded]);
                }

                $updates = ['payment_status' => PaymentStatus::Refunded];
                if ($order->order_status !== OrderStatus::Completed) {
                    $updates['order_status'] = OrderStatus::Cancelled;
                }
                $order->update($updates);
                if ($order->order_status === OrderStatus::Cancelled && ! $wasAlreadyCancelled) {
                    $this->recordGuestNotification($order, GuestOrderNotificationEvent::OrderCancelled);
                }

                $refunded = $order->fresh(['payments']);

                return new OrderLifecycleResult(
                    order: $refunded,
                    payment: $refunded->payments->firstWhere('id', $payment->id),
                    newlyApplied: ! $wasAlreadyRefunded,
                );
            });
        }, 5);
    }

    public function markPaymentExpired(Payment $payment): ?Order
    {
        return $this->applyPaymentExpired($payment)->order;
    }

    public function applyPaymentExpired(Payment $payment): OrderLifecycleResult
    {
        $initial = Payment::findOrFail($payment->id);

        return $this->releasePendingByOrderId($initial->order_id, OrderStatus::Expired, PaymentStatus::Expired, false);
    }

    public function expirePendingOrder(Order $order): ?Order
    {
        return $this->applyPendingExpiry($order)->order;
    }

    public function applyPendingExpiry(Order $order): OrderLifecycleResult
    {
        $order->loadMissing('payments');

        if ($order->payments->contains(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->status === PaymentStatus::Pending
            && $payment->binance_verification_grace_until?->isFuture())) {
            return new OrderLifecycleResult(
                order: null,
                payment: $order->payments->first(),
                newlyApplied: false,
            );
        }

        return $this->releasePendingByOrderId($order->id, OrderStatus::Expired, PaymentStatus::Expired, true);
    }

    public function cancelPendingOrder(Order $order, bool $requireNoPaymentProof = false): Order
    {
        return $this->applyPendingCancellation($order, $requireNoPaymentProof)->order;
    }

    public function applyPendingCancellation(Order $order, bool $requireNoPaymentProof = false): OrderLifecycleResult
    {
        return $this->releasePendingByOrderId($order->id, OrderStatus::Cancelled, PaymentStatus::Failed, false, $requireNoPaymentProof);
    }

    public function project(Order $order, ?Payment $payment = null): OrderLifecycleProjection
    {
        return OrderLifecycleProjection::from($order, $payment);
    }

    private function releasePendingByOrderId(int|string $orderId, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue, bool $requireNoPaymentProof = false): OrderLifecycleResult
    {
        return DB::transaction(function () use ($orderId, $orderStatus, $paymentStatus, $mustBeDue, $requireNoPaymentProof) {
            return $this->withLockedOrderPayments($orderId, function (Order $order, $payments) use ($orderStatus, $paymentStatus, $mustBeDue, $requireNoPaymentProof): OrderLifecycleResult {
                if ($requireNoPaymentProof && $order->order_status === OrderStatus::PendingPayment) {
                    if ($order->payment_status !== PaymentStatus::Pending || ! $payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending)) {
                        throw new InvalidOrderTransition('Only pending payments can be cancelled by the customer.');
                    }

                    if ($payments->contains(fn (Payment $payment): bool => $payment->payment_proof_submitted_at !== null)) {
                        throw new InvalidOrderTransition('Payment proof is being processed.');
                    }
                }

                return $this->applyReleaseTransition($order, $payments, $orderStatus, $paymentStatus, $mustBeDue);
            });
        }, 5);
    }

    private function withLockedOrderPayments(int|string $orderId, callable $callback): mixed
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $payments = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        return $callback($order, $payments);
    }

    private function applyPaidTransition(Payment $payment, Order $order): OrderLifecycleResult
    {
        if ($order->order_status === OrderStatus::Expired || $order->order_status === OrderStatus::Cancelled) {
            return $this->markLatePaymentPaid($payment, $order);
        }

        if ($payment->status === PaymentStatus::Paid && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true) && $this->inventory->hasSaleProofForOrder($order)) {
            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->firstWhere('id', $payment->id),
                newlyApplied: false,
                receiptEligible: ! $this->supplierFulfillment()->hasPending($order),
                salesProofEligible: ! $this->supplierFulfillment()->hasPending($order),
            );
        }

        if ($payment->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition('Payment cannot be marked paid from current state.');
        }

        $this->inventory->recordOrderSales($order);
        $this->supplierFulfillment()->paid($order);

        $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'order_status' => $this->supplierFulfillment()->hasPending($order) ? OrderStatus::Processing : $this->paidOrderStatus($payment),
        ]);
        if ($this->needsLocalCredentialReview($order)) {
            $review = ManualFulfillmentReview::query()->firstOrCreate(
                ['payment_id' => $payment->id],
                [
                    'order_id' => $order->id,
                    'amount' => $payment->provider_total_payment ?? $payment->amount,
                    'currency' => $payment->currency,
                    'reason' => ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING,
                    'state' => ManualFulfillmentReview::STATE_PENDING,
                    'provider_reference' => $payment->provider_reference ?: $payment->merchant_ref,
                ],
            );
            $this->dispatchManualReviewNotice($review);
        }
        $this->recordGuestNotification($order, GuestOrderNotificationEvent::PaymentConfirmed);
        if ($order->order_status === OrderStatus::Completed) {
            $this->recordGuestNotification($order, GuestOrderNotificationEvent::FulfillmentCompleted);
        }
        $this->dispatchAdminPendingNotificationDelete($order);

        $salesProofEnabled = ! $this->supplierFulfillment()->hasPending($order)
            && ($order->api_order_claim_id === null || app(SalesProofEligibility::class)->rejectionReason($order) === null)
            && (bool) StoreChannelSetting::current()->sales_proof_enabled;

        if ($salesProofEnabled) {
            $salesProofPost = SalesProofPost::query()->firstOrCreate(
                ['order_id' => $order->id],
                ['status' => SalesProofPost::STATUS_PENDING],
            );

            $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
            $effect = $outboundEffects->recordSalesProofPost($order, $salesProofPost);
            if ($effect !== null) {
                ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);
            }
        }

        $this->recordAndDispatchAdminOrderPaid($order);

        $paid = $order->fresh(['payments']);
        if (! $this->supplierFulfillment()->hasPending($paid)) {
            $this->dispatchReceiptMaterialization($paid);
        }
        $this->credentialMails()->record($paid);

        return new OrderLifecycleResult(
            order: $paid,
            payment: $paid->payments->firstWhere('id', $payment->id),
            newlyApplied: true,
            receiptEligible: ! $this->supplierFulfillment()->hasPending($paid) && in_array($paid->order_status, [OrderStatus::Processing, OrderStatus::Completed], true),
            salesProofEligible: $salesProofEnabled,
        );
    }

    private function needsLocalCredentialReview(Order $order): bool
    {
        return ! SupplierFulfillment::query()->where('order_id', $order->id)->exists()
            && $order->orderItems()->sum('quantity') > 0
            && ! ProductStockItem::query()
                ->whereIn('order_item_id', $order->orderItems()->select('id'))
                ->whereNull('supplier_purchase_id')
                ->exists();
    }

    private function dispatchManualReviewNotice(ManualFulfillmentReview $review): void
    {
        $effect = ($this->outboundEffects ?? app(TelegramOutboundEffectService::class))
            ->recordAdminOrderReviewNotification($review);
        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);
        }
    }

    private function paidOrderStatus(Payment $payment): OrderStatus
    {
        return strtolower((string) $payment->provider) === 'pakasir'
            ? OrderStatus::Completed
            : OrderStatus::Processing;
    }

    private function markLatePaymentPaid(Payment $payment, Order $order): OrderLifecycleResult
    {
        if ($payment->status === PaymentStatus::Refunded) {
            if ($order->payment_status !== PaymentStatus::Refunded) {
                $order->update(['payment_status' => PaymentStatus::Refunded]);
            }

            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->firstWhere('id', $payment->id),
                newlyApplied: false,
            );
        }

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Expired, PaymentStatus::Failed], true)) {
            throw new InvalidOrderTransition('Late payment cannot be marked paid from current payment state.');
        }

        $changed = false;
        if ($payment->status !== PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => $payment->paid_at ?? now()]);
            $changed = true;
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->update(['payment_status' => PaymentStatus::Paid]);
            $changed = true;
        }

        $review = ManualFulfillmentReview::query()->firstOrCreate(
            ['payment_id' => $payment->id],
            [
                'order_id' => $order->id,
                'amount' => $payment->provider_total_payment ?? $payment->amount,
                'currency' => $payment->currency,
                'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
                'state' => ManualFulfillmentReview::STATE_PENDING,
                'provider_reference' => $payment->provider_reference ?: $payment->merchant_ref,
            ],
        );
        if ($this->needsLocalCredentialReview($order)) {
            $this->dispatchManualReviewNotice($review);
        }

        if ($changed) {
            $this->dispatchAdminPendingNotificationDelete($order);
            $this->recordGuestNotification($order, GuestOrderNotificationEvent::PaymentConfirmed);
        }

        $latePaid = $order->fresh(['payments']);

        return new OrderLifecycleResult(
            order: $latePaid,
            payment: $latePaid->payments->firstWhere('id', $payment->id),
            newlyApplied: $changed,
        );
    }

    private function applyReleaseTransition(Order $order, Collection $payments, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue): OrderLifecycleResult
    {
        if ($order->order_status === $orderStatus) {
            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->first(),
                newlyApplied: false,
            );
        }

        if ($order->order_status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition('Only pending-payment orders can be released.');
        }

        if ($mustBeDue && $order->expires_at && $order->expires_at->isFuture()) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        if ($mustBeDue && $this->hasPendingManualPaymentProof($payments)) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        if ($mustBeDue && $payments->contains(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->status === PaymentStatus::Pending
            && $payment->binance_verification_grace_until?->isFuture())) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        if ($mustBeDue && $payments->contains(fn (Payment $payment): bool => $this->bscConfirmationGrace()->isActive($payment, $order))) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        $this->inventory->releaseOrderReservations($order);
        $this->supplierFulfillment()->release($order);
        $this->credentials()->release($order);

        foreach ($payments->where('status', PaymentStatus::Pending) as $payment) {
            $payment->update(['status' => $paymentStatus]);
            if ($payment->method === 'USDT_BEP20') {
                $payment->bscDetail()->update([
                    'verification_status' => $orderStatus === OrderStatus::Expired
                        ? 'expired'
                        : 'manual_review',
                    'last_verified_at' => now(),
                ]);
            }
            $this->providerCancellationAttempts()->schedule($payment);
        }
        $order->update([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            ...($orderStatus === OrderStatus::Cancelled ? ['checkout_key' => null] : []),
        ]);
        if ($orderStatus === OrderStatus::Expired) {
            $this->recordGuestNotification($order, GuestOrderNotificationEvent::OrderExpired);
        } elseif ($orderStatus === OrderStatus::Cancelled) {
            $this->recordGuestNotification($order, GuestOrderNotificationEvent::OrderCancelled);
        }
        $this->dispatchAdminPendingNotificationDelete($order);
        if ($orderStatus === OrderStatus::Cancelled) {
            DeleteBuyerCheckoutMessages::dispatch((string) $order->id, now()->toISOString())
                ->onQueue('notifications')
                ->afterCommit();
        }
        if ($orderStatus === OrderStatus::Expired && $paymentStatus === PaymentStatus::Expired) {
            $this->recordAndDispatchAdminOrderExpired($order);
        }

        $released = $order->fresh(['payments']);

        return new OrderLifecycleResult(
            order: $released,
            payment: $released->payments->first(),
            newlyApplied: true,
        );
    }

    private function hasPendingManualPaymentProof(Collection $payments): bool
    {
        return $payments->contains(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'QR_MANUAL'
            && $payment->status === PaymentStatus::Pending
            && $payment->payment_proof_submitted_at !== null);
    }

    private function canTransitionManualOrder(Order $order, ?Payment $payment): bool
    {
        return $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending
            && $payment?->provider === 'manual'
            && $payment->status === PaymentStatus::Pending;
    }

    private function lockStoreBalanceAccount(Payment $payment): ?int
    {
        if (! in_array($payment->provider, [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)) {
            return null;
        }

        $order = Order::query()
            ->select(['id', 'customer_id'])
            ->find($payment->order_id);
        $customerId = $order?->customer_id;
        if ($customerId === null) {
            throw new InvalidOrderTransition('Store Balance refund customer is missing.');
        }

        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            throw new InvalidOrderTransition('Store Balance refund customer is missing.');
        }

        if ($payment->provider === PaymentProvider::USDT_BALANCE) {
            app(CustomerUsdtBalanceLedger::class)->lockForTransaction($customer);
        } else {
            $this->storeBalanceLedger()->lockForTransaction($customer);
        }

        return (int) $customer->getKey();
    }

    private function refundStoreBalance(Order $order, Payment $payment, ?int $lockedCustomerId): void
    {
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::STORE_BALANCE) {
            return;
        }

        $paymentStatus = $payment->status;
        $orderPaymentStatus = $order->payment_status;
        $alreadyRefunded = $paymentStatus === PaymentStatus::Refunded
            && $orderPaymentStatus === PaymentStatus::Refunded
            && in_array($order->order_status, [OrderStatus::Cancelled, OrderStatus::Completed], true);
        $isPaid = $paymentStatus === PaymentStatus::Paid
            && $orderPaymentStatus === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);

        if ($lockedCustomerId === null
            || (int) $order->customer_id !== $lockedCustomerId
            || strtoupper(trim((string) $payment->method)) !== 'STORE_BALANCE'
            || strtoupper(trim((string) $payment->currency)) !== 'IDR'
            || strtoupper(trim((string) $order->currency)) !== 'IDR'
            || (! $alreadyRefunded && ! $isPaid)
            || (int) $payment->amount !== (int) $order->total_amount
            || (int) $payment->amount <= 0) {
            throw new InvalidOrderTransition('Store Balance refund does not match the original IDR Order debit.');
        }

        $customer = Customer::query()->find($order->customer_id);
        if ($customer === null) {
            throw new InvalidOrderTransition('Store Balance refund customer is missing.');
        }

        $this->storeBalanceLedger()->refund(
            customer: $customer,
            amount: (int) $payment->amount,
            sourceType: 'order',
            sourceId: (string) $order->getKey(),
            idempotencyKey: 'order:'.$order->getKey().':refund',
        );
    }

    private function storeBalanceLedger(): StoreBalanceLedger
    {
        return $this->storeBalance ?? app(StoreBalanceLedger::class);
    }

    private function releaseRefundedOrderResources(Order $order): void
    {
        $this->supplierFulfillment()->release($order);
        if (! $this->inventory->hasSaleProofForOrder($order)) {
            $this->inventory->releaseOrderReservations($order);
            $this->credentials()->release($order);

            return;
        }

        $this->credentials()->retire($order);
    }

    private function supplierFulfillment(): SupplierFulfillmentService
    {
        return app(SupplierFulfillmentService::class);
    }

    private function credentials(): ProductCredentialAllocator
    {
        return $this->credentials ?? app(ProductCredentialAllocator::class);
    }

    private function providerCancellationAttempts(): ProviderCancellationAttempts
    {
        return $this->providerCancellationAttempts ?? app(ProviderCancellationAttempts::class);
    }

    private function bscConfirmationGrace(): BscConfirmationGrace
    {
        return $this->bscConfirmationGrace ?? app(BscConfirmationGrace::class);
    }

    private function recordGuestNotification(Order $order, GuestOrderNotificationEvent $eventType): void
    {
        ($this->guestOrderNotifications ?? app(GuestOrderNotificationService::class))->record($order, $eventType);
    }

    private function credentialMails(): OrderCredentialMailService
    {
        return $this->credentialMails ?? app(OrderCredentialMailService::class);
    }

    private function dispatchAdminPendingNotificationDelete(Order $order): void
    {
        DeleteAdminPendingOrderNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }

    private function dispatchAdminExpiredNotification(Order $order): void
    {
        SendAdminOrderExpiredNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }

    private function recordAndDispatchAdminOrderPaid(Order $order): void
    {
        $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordAdminOrderPaidNotification($order);

        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);

            return;
        }

        if (! $outboundEffects->hasConfiguredAdminTarget()) {
            SendAdminOrderPaidNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
        }
    }

    private function recordAndDispatchAdminOrderExpired(Order $order): void
    {
        $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordAdminOrderExpiredNotification($order);

        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);

            return;
        }

        if (! $outboundEffects->hasConfiguredAdminTarget()) {
            $this->dispatchAdminExpiredNotification($order);
        }
    }

    private function dispatchReceiptMaterialization(Order $order): void
    {
        DB::afterCommit(function () use ($order): void {
            try {
                SendTelegramReceipt::dispatch((string) $order->getKey())
                    ->onQueue(TelegramOutboundEffectService::RECEIPT_QUEUE);
            } catch (Throwable $exception) {
                Log::warning('telegram.receipt_handoff_dispatch_failed', [
                    'order_id' => (string) $order->getKey(),
                    'stage' => 'order_lifecycle_after_commit',
                    'status' => 'failed',
                    'error_code' => 'receipt_dispatch_failed',
                    'exception' => $exception::class,
                ]);

                throw $exception;
            }
        });
    }
}
