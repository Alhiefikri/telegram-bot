<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontShopBinanceOrderIdController extends Controller
{
    public function __invoke(Request $request, Order $order, BinancePaymentVerification $verification, StorefrontInvoiceUrl $urls): RedirectResponse
    {
        $validated = $request->validate([
            'binance_order_id' => ['bail', 'required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{8,126}[A-Za-z0-9]\z/'],
        ], [
            'binance_order_id.regex' => 'Please enter the Binance Pay Order ID exactly as shown in Order Details.',
        ]);

        $result = $verification->submitOrderId($order->id, $validated['binance_order_id']);
        $showUrl = $urls->show($order);

        return match ($result->outcome) {
            BinanceVerificationSubmissionOutcome::Accepted => redirect($showUrl)->with('status', 'Order ID received. We are checking your Binance payment securely. This page will update when verification is complete.'),
            BinanceVerificationSubmissionOutcome::Limit => redirect($showUrl)->withErrors(['binance_order_id' => 'You have reached the maximum number of Order ID submissions for this payment. Please contact support.']),
            BinanceVerificationSubmissionOutcome::Expired => redirect($showUrl)->withErrors(['binance_order_id' => 'This payment window has ended. Please contact support if you already paid.']),
            BinanceVerificationSubmissionOutcome::GraceExpired => redirect($showUrl)->withErrors(['binance_order_id' => 'Automatic verification for this payment has closed after a previous check. Please contact support with your payment proof.']),
            BinanceVerificationSubmissionOutcome::Manual => redirect($showUrl)->withErrors(['binance_order_id' => 'Automatic Binance verification is not available for this payment. Please contact support with your payment proof.']),
            default => redirect($showUrl)->withErrors(['binance_order_id' => 'Please enter a valid Binance Pay Order ID.']),
        };
    }
}
