<?php

namespace App\Models;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Durable active-target reservation: the single open Customer USDT funding
 * target across rails. The identity columns are immutable; only the state and
 * resolution columns may transition once, through the reservation service.
 * Expiry moves the row to expired_unresolved and keeps holding the slot.
 */
#[Fillable([
    'customer_id',
    'source_type',
    'source_id',
    'rail',
    'amount_micro',
    'fee_micro',
    'policy_version',
    'terms_version',
    'request_fingerprint',
    'state',
    'reserved_at',
    'expires_at',
    'resolved_at',
    'resolution_kind',
    'resolution_reference',
    'resolution_actor',
    'settled_entry_id',
])]
class CustomerUsdtFundingReservation extends Model
{
    private const IDENTITY_COLUMNS = [
        'customer_id',
        'source_type',
        'source_id',
        'rail',
        'amount_micro',
        'fee_micro',
        'policy_version',
        'terms_version',
        'request_fingerprint',
        'reserved_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'rail' => CustomerUsdtFundingRail::class,
            'amount_micro' => 'integer',
            'fee_micro' => 'integer',
            'policy_version' => 'integer',
            'terms_version' => 'integer',
            'state' => CustomerUsdtFundingReservationState::class,
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'settled_entry_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reservation): void {
            if ($reservation->isDirty(self::IDENTITY_COLUMNS)) {
                throw new LogicException('Customer USDT funding reservation identity is immutable.');
            }

            if ($reservation->getOriginal('resolved_at') !== null) {
                throw new LogicException('Resolved Customer USDT funding reservations are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Customer USDT funding reservations cannot be deleted.');
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function settledEntry()
    {
        return $this->belongsTo(CustomerUsdtBalanceEntry::class, 'settled_entry_id');
    }
}
