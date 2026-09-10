<?php

namespace App\Models;

use App\Enums\StoreBalanceEntryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'customer_balance_account_id',
    'kind',
    'amount',
    'balance_after',
    'source_type',
    'source_id',
    'idempotency_key',
    'created_at',
])]
class StoreBalanceEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => StoreBalanceEntryKind::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Store Balance ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Store Balance ledger entries are immutable.');
        });
    }

    public function account()
    {
        return $this->belongsTo(CustomerBalanceAccount::class, 'customer_balance_account_id');
    }
}
