<?php

namespace App\Services\Sakurupiah;

/**
 * Safe result of one authoritative Sakurupiah reconciliation attempt.
 *
 * Only aggregate/redacted identifiers cross the module boundary; provider
 * payloads never travel with these outcomes.
 */
enum SakurupiahReconciliationOutcome: string
{
    case Paid = 'paid';

    case Expired = 'expired';

    case Pending = 'pending';

    case VerificationPending = 'verification_pending';

    case Invalid = 'invalid';

    case Busy = 'busy';
}
