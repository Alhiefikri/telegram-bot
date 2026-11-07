<?php

namespace App\Services\WijayaPay;

enum WijayaPayReconciliationOutcome: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case VerificationPending = 'verification_pending';
    case Invalid = 'invalid';
    case Busy = 'busy';
}
