<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontPaymentTerminalCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontShopBinanceOrderIdController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        BinancePaymentVerification $verification,
        StorefrontInvoiceUrl $urls,
        StorefrontPaymentTerminalCopy $copy,
    ): RedirectResponse {
        $validated = $request->validate([
            'binance_order_id' => ['bail', 'required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{8,126}[A-Za-z0-9]\z/'],
        ], $copy->binanceOrderIdValidationMessages());

        $result = $verification->submitOrderId($order->id, $validated['binance_order_id']);
        $showUrl = $urls->show($order);

        return match ($result->outcome) {
            BinanceVerificationSubmissionOutcome::Accepted => redirect($showUrl)->with('status', $copy->binanceOrderIdSubmissionMessage($result->outcome, $result->dispatchFailed)),
            BinanceVerificationSubmissionOutcome::Limit,
            BinanceVerificationSubmissionOutcome::Expired,
            BinanceVerificationSubmissionOutcome::GraceExpired,
            BinanceVerificationSubmissionOutcome::Manual,
            BinanceVerificationSubmissionOutcome::Invalid => redirect($showUrl)->withErrors([
                'binance_order_id' => $copy->binanceOrderIdSubmissionMessage($result->outcome),
            ]),
        };
    }
}
