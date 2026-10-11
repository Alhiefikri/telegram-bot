<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderCredentialMailService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer-triggered resend of the credential email.
 *
 * The address is never supplied by the request: the durable row snapshot made
 * at checkout time is the only recipient. The action is therefore reveal-gated
 * and throttled instead of signature-gated, so it also works from the
 * key-authenticated order access page.
 */
class StorefrontShopCredentialMailResendController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        StorefrontOrderAccess $access,
        OrderCredentialMailService $mails,
    ): RedirectResponse {
        $canRevealCredentials = (! $access->hasKey($order) && blank($order->checkout_key))
            || $access->canAccess($request, $order);

        if (! $canRevealCredentials) {
            abort(404);
        }

        $event = $mails->eventFor($order);
        if ($event === null || ! $mails->isDeliverable($order)) {
            return back()->withErrors([
                'credential_mail' => (string) config('storefront.credential_email.resend_unavailable'),
            ]);
        }

        $rearmed = $mails->rearm($order);
        if ($rearmed === null) {
            return back()->withErrors([
                'credential_mail' => (string) config('storefront.credential_email.resend_pending'),
            ]);
        }

        return back()->with('status', str_replace(
            ':email',
            OrderCredentialMailService::maskedRecipient($rearmed->recipient_email) ?? 'email pesanan',
            (string) config('storefront.credential_email.resend_sent'),
        ));
    }
}
