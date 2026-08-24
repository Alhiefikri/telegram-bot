<?php

namespace App\Services\Bsc;

enum BscVerificationSubmissionOutcome: string
{
    case Accepted = 'accepted';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case GraceExpired = 'grace_expired';
    case Limit = 'limit';
    case AlreadyPaid = 'already_paid';
    case TransactionAlreadyUsed = 'transaction_already_used';
    case OrderNotPending = 'order_not_pending';
    case NotConfigured = 'not_configured';
}
