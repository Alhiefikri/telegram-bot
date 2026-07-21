<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\OrderLifecycle;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirTransaction;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class StorefrontShopOrderCancelController extends Controller
{
    public function __invoke(Request $request, Order $order, OrderLifecycle $orders, AuditLogger $audit, StorefrontInvoiceUrl $urls, PakasirProviderAdapter $pakasir): RedirectResponse
    {
        $wasPending = $order->order_status === OrderStatus::PendingPayment && $order->payment_status === PaymentStatus::Pending;

        if ($wasPending) {
            $payment = $order->payments()->where('provider', 'pakasir')->where('status', PaymentStatus::Pending)->latest('id')->first();
            if ($payment !== null && $payment->payment_proof_submitted_at === null) {
                try {
                    $transaction = PakasirTransaction::fromPayload($pakasir->transactionDetail($payment));
                    if ($transaction === null
                        || $transaction->project() !== config('services.pakasir.slug')
                        || $transaction->amount() !== $payment->amount
                        || $transaction->orderId() !== $payment->merchant_ref
                        || ($payment->provider_payment_method && $transaction->paymentMethod() !== strtolower($payment->provider_payment_method))) {
                        throw new RuntimeException('Pakasir verification mismatch.');
                    }

                    if ($transaction->isCompleted()) {
                        $orders->applyPaymentPaid($payment);

                        return redirect($urls->show($order))
                            ->withErrors(['order' => 'Pembayaran QRIS sudah diterima, jadi pesanan tidak bisa dibatalkan.']);
                    }
                } catch (Throwable) {
                    return redirect($urls->show($order))
                        ->withErrors(['order' => 'Status pembayaran QRIS belum dapat diverifikasi. Pesanan tidak dibatalkan; coba lagi sebentar.']);
                }
            }
        }

        try {
            $cancelled = $orders->applyPendingCancellation($order, requireNoPaymentProof: true)->order;
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
