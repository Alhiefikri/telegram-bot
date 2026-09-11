<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'store_balance_top_up_id',
    'customer_id',
    'owner_user_id',
    'ledger_entry_id',
    'resolution_type',
    'idempotency_key',
    'provider_reference',
    'reason',
    'amount',
    'resolved_at',
    'created_at',
])]
class StoreBalanceCompensation extends Model
{
    protected $table = 'store_balance_compensations';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Store Balance compensation records are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Store Balance compensation records are immutable.');
        });
    }

    public function topUp()
    {
        return $this->belongsTo(StoreBalanceTopUp::class, 'store_balance_top_up_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(StoreBalanceEntry::class, 'ledger_entry_id');
    }
}
