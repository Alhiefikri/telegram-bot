<?php

namespace App\Services\Credentials;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\SupplierPurchase;
use App\Services\OrderLifecycleProjection;
use App\Services\Suppliers\SupplierFulfillmentService;

/**
 * Decides whether a Customer may receive an Order's paid credentials without
 * loading or decrypting any credential-bearing stock fields.
 */
final class CredentialDeliveryEligibility
{
    public function rejectionReason(
        Order $order,
        ?Customer $customer = null,
        ?Payment $payment = null,
    ): ?string {
        if ($customer !== null
            && (string) $customer->getKey() !== (string) $order->customer_id) {
            return 'order_unavailable';
        }

        $order->loadMissing('payments');
        $payment ??= $this->latestPayment($order);

        if ($payment === null
            || (string) $payment->order_id !== (string) $order->getKey()
            || $payment->status !== PaymentStatus::Paid
            || $payment->paid_at === null) {
            return 'payment_not_confirmed';
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            return 'payment_not_paid';
        }

        if (app(SupplierFulfillmentService::class)->hasPending($order)
            && ! SupplierPurchase::query()->whereHas('fulfillment', fn ($q) => $q->where('order_id', $order->id))
                ->whereHas('supplier', fn ($q) => $q->where('provider', 'buymail'))
                ->whereNotNull('committed_at')->where('observed_quantity', '>', 0)->whereColumn('observed_quantity', '<', 'quantity')->exists()) {
            return 'fulfillment_processing';
        }

        if ($order->hasOpenLatePaymentReview()) {
            return 'late_payment_review';
        }

        if (! OrderLifecycleProjection::from($order, $payment)->isPaid) {
            return 'lifecycle_ineligible';
        }

        return null;
    }

    public function allows(
        Order $order,
        ?Customer $customer = null,
        ?Payment $payment = null,
    ): bool {
        return $this->rejectionReason($order, $customer, $payment) === null;
    }

    /**
     * Check for allocated credential rows using only their identifiers. The
     * encrypted legacy and structured fields are never selected or hydrated.
     */
    public function hasAllocatedCredentials(Order $order): bool
    {
        return ProductStockItem::query()
            ->whereHas('orderItem', static fn ($query) => $query->where('order_id', $order->getKey()))
            ->value('id') !== null;
    }

    private function latestPayment(Order $order): ?Payment
    {
        return $order->payments
            ->sortByDesc(fn (Payment $candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();
    }
}
