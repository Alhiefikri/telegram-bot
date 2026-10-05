<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy money evidence that could not be normalized into a claimed
 * ExternalMoneyReceipt. Reservations are the fail-closed denylist: ambiguous
 * identities stay recorded (never discarded) and block the cutover readiness
 * gate until an operator reviews them.
 */
#[Fillable([
    'source',
    'legacy_type',
    'legacy_id',
    'provider',
    'reason',
    'details',
    'reserved_at',
    'resolved_at',
])]
final class ExternalMoneyReceiptReservation extends Model
{
    public const LEGACY_TYPE_PAYMENT = 'payment';

    public const LEGACY_TYPE_STORE_BALANCE_TOP_UP = 'store_balance_top_up';

    public const REASON_MISSING_RECEIVER_NAMESPACE = 'missing_receiver_namespace';

    public const REASON_MISSING_CHAIN_IDENTITY = 'missing_chain_identity';

    public const REASON_UNNORMALIZABLE_IDENTITY = 'unnormalizable_identity';

    public const REASON_REGISTRY_CONFLICT = 'registry_conflict';

    public const REASON_REGISTRY_REVIEW = 'registry_review';

    public const REASON_COMPENSATED_UNRESOLVED_MONEY = 'compensated_unresolved_money';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'reserved_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
