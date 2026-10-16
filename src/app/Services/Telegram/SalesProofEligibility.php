<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\InventoryLedger;
use App\Services\Suppliers\SupplierFulfillmentService;

final class SalesProofEligibility
{
    public function rejectionReason(Order $order): ?string
    {
        if (app(SupplierFulfillmentService::class)->hasPending($order)) {
            return 'Order fulfillment is processing.';
        }
        $order->loadMissing('payments');
        if ($order->payment_status !== PaymentStatus::Paid
            || ! $order->payments->contains(fn ($payment): bool => $payment->status === PaymentStatus::Paid)
            || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            return 'Sales Proof Post requires a paid processable order.';
        }
        if (! app(InventoryLedger::class)->hasSaleProofForOrder($order)) {
            return 'Sales Proof Post requires matching inventory sale proof.';
        }
        if ($order->api_order_claim_id === null) {
            return null;
        }
        if (! app(CredentialDeliveryEligibility::class)->allows($order)
            || ManualFulfillmentReview::query()->where('order_id', $order->id)->open()->exists()) {
            return 'API fulfillment is not authorized for completion.';
        }

        // Count identifiers only. Public proof must never hydrate credential fields.
        $items = $order->orderItems()->withCount(['stockItems' => fn ($q) => $q->whereNull('retired_at')])->get(['id', 'quantity']);
        if ($items->isEmpty() || $items->contains(fn ($item): bool => $item->quantity < 1 || $item->stock_items_count !== $item->quantity)) {
            return 'API fulfillment is incomplete.';
        }

        return null;
    }
}
