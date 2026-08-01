<?php

namespace App\Services\Binance;

enum BinanceVerificationOutcome: string
{
    case Confirmed = 'confirmed';
    case AlreadyPaid = 'already_paid';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
    case TransactionAlreadyUsed = 'transaction_already_used';
    case OrderNotPending = 'order_not_pending';
    case NotConfigured = 'not_configured';
    case ProviderError = 'provider_error';
    case IncompleteWindow = 'incomplete_window';
    case StaleSubmission = 'stale_submission';
}
