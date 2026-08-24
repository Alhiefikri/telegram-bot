<?php

namespace App\Services\Bsc;

enum BscVerificationOutcome: string
{
    case AwaitingTransfer = 'awaiting_transfer';
    case TxSubmitted = 'tx_submitted';
    case Confirming = 'confirming';
    case Confirmed = 'confirmed';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
    case TransactionAlreadyUsed = 'transaction_already_used';
    case ProviderError = 'provider_error';
    case ManualReview = 'manual_review';
    case Expired = 'expired';
    case AlreadyPaid = 'already_paid';
    case OrderNotPending = 'order_not_pending';
    case NotConfigured = 'not_configured';
    case StaleSubmission = 'stale_submission';
}
