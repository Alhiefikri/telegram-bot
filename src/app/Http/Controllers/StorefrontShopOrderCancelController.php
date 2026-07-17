<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontShopOrderCancelController extends Controller
{
    public function __invoke(Request $request, Order $order, OrderStateMachine $orders, AuditLogger $audit, StorefrontInvoiceUrl $urls): RedirectResponse
    {
        $wasPending = $order->order_status === OrderStatus::PendingPayment && $order->payment_status === PaymentStatus::Pending;

        try {
            $cancelled = $orders->cancelPendingOrder($order, requireNoPaymentProof: true);
        } catch (InvalidOrderTransition) {
            return redirect($urls->show($order))
                ->withErrors(['order' => 'Pesanan sudah diproses atau bukti pembayaran sedang diverifikasi, jadi tidak bisa dibatalkan.']);
        }

        if ($wasPending) {
            $audit->log('order.pending_cancelled_released', $cancelled, [
                'order_status' => OrderStatus::PendingPayment->value,
                'payment_status' => PaymentStatus::Pending->value,
            ], [
                'order_status' => OrderStatus::Cancelled->value,
                'payment_status' => PaymentStatus::Failed->value,
                'actor' => 'customer',
                'actor_id' => $cancelled->customer_id,
                'channel' => 'storefront_web',
                'reason' => 'customer_requested_cancel',
            ], ip: $request->ip());
        }

        return redirect($urls->show($cancelled))
            ->with('status', 'Pesanan dibatalkan. Stok dan data akun sudah dilepas.');
    }
}
