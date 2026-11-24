<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\SupplierFulfillment;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;

final class TelegramLocalFulfillmentLink
{
    use HasAdminWebAppButton;

    public function reviewFor(Order $order): ?ManualFulfillmentReview
    {
        if ($order->payment_status !== PaymentStatus::Paid || $order->order_status !== OrderStatus::Processing) {
            return null;
        }

        $reviews = ManualFulfillmentReview::query()->where('order_id', $order->id)->open()->limit(2)->get();
        $review = $reviews->count() === 1 ? $reviews->first() : null;
        if ($review?->reason !== ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING
            || $review->state !== ManualFulfillmentReview::STATE_PENDING
            || ! $order->payments->contains(fn ($payment) => $payment->id === $review->payment_id && $payment->status === PaymentStatus::Paid)
            || OrderRefund::query()->where('order_id', $order->id)->exists()
            || SupplierFulfillment::query()->where('order_id', $order->id)->exists()
            || $order->orderItems->contains(fn ($item) => $item->supplier_quantity > 0)
            || ! $order->orderItems()->whereRaw('(SELECT COUNT(*) FROM product_stock_items WHERE product_stock_items.order_item_id = order_items.id AND supplier_purchase_id IS NULL AND retired_at IS NULL) < COALESCE(local_quantity, quantity)')->exists()) {
            return null;
        }

        return $review;
    }

    public function url(ManualFulfillmentReview $review): ?string
    {
        $url = $this->reviewUrl($review);

        return $url === null ? null : $url.'#local-credentials';
    }

    public function reviewUrl(ManualFulfillmentReview $review): ?string
    {
        // Use the configured origin, not the webhook request Host. This URL grants no authority.
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! $this->isTelegramDashboardUrl($origin) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $origin.route('admin.manual-fulfillment-reviews.show', $review, absolute: false);
    }
}
