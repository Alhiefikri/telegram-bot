<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontPaymentTerminalCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class StorefrontShopBscTxHashController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        BscPaymentVerification $verification,
        StorefrontInvoiceUrl $urls,
        StorefrontPaymentTerminalCopy $copy,
    ): RedirectResponse {
        $showUrl = $urls->show($order);
        $validator = Validator::make($request->all(), [
            'bsc_tx_hash' => ['bail', 'required', 'string', 'max:66', 'regex:/\A0[xX][0-9a-fA-F]{64}\z/'],
        ], $copy->bscTxHashValidationMessages());
        if ($validator->fails()) {
            return redirect($showUrl)->withErrors($validator);
        }

        $result = $verification->submitTransactionHash($order->id, $validator->validated()['bsc_tx_hash']);

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
