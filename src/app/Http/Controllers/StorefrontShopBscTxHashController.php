<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontPaymentTerminalCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StorefrontShopBscTxHashController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        BscPaymentVerification $verification,
        StorefrontInvoiceUrl $urls,
        StorefrontPaymentTerminalCopy $copy,
    ): RedirectResponse {
        $validated = $request->validate([
            'bsc_tx_hash' => ['bail', 'required', 'string', 'max:66', 'regex:/\A0x[0-9a-fA-F]{64}\z/'],
        ], $copy->bscTxHashValidationMessages());

        $result = $verification->submitTransactionHash($order->id, $validated['bsc_tx_hash']);
        $showUrl = $urls->show($order);

        return match ($result->outcome) {
            BscVerificationSubmissionOutcome::Accepted => redirect($showUrl)->with(
                'status',
                $copy->bscTxHashSubmissionMessage($result->outcome, $result->dispatchFailed),
            ),
            BscVerificationSubmissionOutcome::AlreadyPaid => redirect($showUrl)->with(
                'status',
                $copy->bscTxHashSubmissionMessage($result->outcome),
            ),
            BscVerificationSubmissionOutcome::Limit,
            BscVerificationSubmissionOutcome::Expired,
            BscVerificationSubmissionOutcome::GraceExpired,
            BscVerificationSubmissionOutcome::TransactionAlreadyUsed,
            BscVerificationSubmissionOutcome::OrderNotPending,
            BscVerificationSubmissionOutcome::NotConfigured,
            BscVerificationSubmissionOutcome::Invalid => redirect($showUrl)->withErrors([
                'bsc_tx_hash' => $copy->bscTxHashSubmissionMessage($result->outcome),
            ]),
        };
    }
}
