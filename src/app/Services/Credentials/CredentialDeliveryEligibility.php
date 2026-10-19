<?php

namespace App\Services\Credentials;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Services\OrderLifecycleProjection;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Database\Eloquent\Collection;

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
        return $this->rejectionReasons(new Collection([$order]), $customer, $payment === null ? [] : [$order->id => $payment])[$order->id];
    }

    /**
     * Batch the same entitlement decision without selecting credential fields.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, Payment>  $payments
     * @return array<string, ?string>
     */
    public function rejectionReasons(Collection $orders, ?Customer $customer = null, array $payments = []): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $orders->loadMissing('payments');
        $ids = $orders->modelKeys();
        $pending = collect(app(SupplierFulfillmentService::class)->pendingOrderIds($ids))->flip();
        $review = ManualFulfillmentReview::query()->whereIn('order_id', $ids)->openLatePayment()->pluck('order_id')->flip();

        $reasons = [];
        foreach ($orders as $order) {
            $payment = $payments[$order->id] ?? $this->latestPayment($order);
            $reasons[$order->id] = match (true) {
                $customer !== null && (string) $customer->id !== (string) $order->customer_id => 'order_unavailable',
                $payment === null,
                (string) $payment->order_id !== (string) $order->id,
                $payment->status !== PaymentStatus::Paid,
                $payment->paid_at === null => 'payment_not_confirmed',
                $order->payment_status !== PaymentStatus::Paid => 'payment_not_paid',
                $pending->has($order->id) => 'fulfillment_processing',
                $review->has($order->id) => 'late_payment_review',
                ! OrderLifecycleProjection::from($order, $payment)->isPaid => 'lifecycle_ineligible',
                default => null,
            };
        }

        return $reasons;
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
