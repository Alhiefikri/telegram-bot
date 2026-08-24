<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StorefrontShopBscTxHashController extends Controller
{
    public function __invoke(Request $request, Order $order, BscPaymentVerification $verification, StorefrontInvoiceUrl $urls): RedirectResponse
    {
        $validated = $request->validate([
            'bsc_tx_hash' => ['bail', 'required', 'string', 'max:66', 'regex:/\A0x[0-9a-fA-F]{64}\z/'],
        ], [
            'bsc_tx_hash.regex' => 'Please enter a BSC transaction hash beginning with 0x and containing 64 hexadecimal characters.',
        ]);

        $result = $verification->submitTransactionHash($order->id, $validated['bsc_tx_hash']);
        $showUrl = $urls->show($order);

        return match ($result->outcome) {
            BscVerificationSubmissionOutcome::Accepted => redirect($showUrl)->with(
                'status',
                $result->dispatchFailed
                    ? 'Transaction hash received. Verification will continue through recovery.'
                    : 'Transaction hash received. We are checking the BSC transfer securely.',
            ),
            BscVerificationSubmissionOutcome::Limit => redirect($showUrl)->withErrors(['bsc_tx_hash' => 'The hash submission limit for this payment has been reached. Please contact support.']),
            BscVerificationSubmissionOutcome::Expired,
            BscVerificationSubmissionOutcome::GraceExpired => redirect($showUrl)->withErrors(['bsc_tx_hash' => 'This BSC payment window has ended. Contact support if you already transferred funds.']),
            BscVerificationSubmissionOutcome::AlreadyPaid => redirect($showUrl)->with('status', 'This payment is already confirmed.'),
            BscVerificationSubmissionOutcome::TransactionAlreadyUsed => redirect($showUrl)->withErrors(['bsc_tx_hash' => 'This transaction hash is already linked to another payment. Please contact support.']),
            BscVerificationSubmissionOutcome::OrderNotPending,
            BscVerificationSubmissionOutcome::NotConfigured => redirect($showUrl)->withErrors(['bsc_tx_hash' => 'This order is no longer waiting for BSC payment.']),
            BscVerificationSubmissionOutcome::Invalid => redirect($showUrl)->withErrors(['bsc_tx_hash' => 'Please enter a valid BSC transaction hash.']),
        };
    }
}
