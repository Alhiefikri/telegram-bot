<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\CustomerCancellationOutcome;
use App\Services\CustomerPendingOrderCancellation;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontShopOrderCancelController extends Controller
{
    public function __invoke(Request $request, Order $order, CustomerPendingOrderCancellation $cancellation, AuditLogger $audit, StorefrontInvoiceUrl $urls): RedirectResponse
    {
        $outcome = $cancellation->cancel($order);

        if ($outcome === CustomerCancellationOutcome::Paid) {
            return redirect($urls->show($order))
                ->withErrors(['order' => StorefrontLocale::text('cancel_paid')]);
        }

        if ($outcome === CustomerCancellationOutcome::VerificationUnavailable) {
            return redirect($urls->show($order))
                ->withErrors(['order' => StorefrontLocale::text('cancel_unverified')]);
        }

        if ($outcome !== CustomerCancellationOutcome::Cancelled) {
            return redirect($urls->show($order))
                ->withErrors(['order' => StorefrontLocale::text('cancel_in_progress')]);
        }

        $cancelled = $order->fresh();

        $audit->log('order.pending_cancelled_released', $cancelled, [
            'order_status' => 'pending_payment',
            'payment_status' => 'pending',
        ], [
            'order_status' => 'cancelled',
            'payment_status' => 'failed',
            'actor' => 'customer',
            'actor_id' => $cancelled->customer_id,
            'channel' => 'storefront_web',
            'reason' => 'customer_requested_cancel',
        ], ip: $request->ip());

        return redirect($urls->show($cancelled))
            ->with('status', StorefrontLocale::text('cancel_success'));
    }
}
