<?php

namespace App\Models;

use App\Enums\UsdtBalanceEntryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'customer_usdt_balance_account_id',
    'account_sequence',
    'kind',
    'amount_micro',
    'balance_after_micro',
    'source_type',
    'source_id',
    'idempotency_key',
    'request_fingerprint',
    'original_entry_id',
    'refund_order_id',
    'created_at',
])]
class CustomerUsdtBalanceEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => UsdtBalanceEntryKind::class,
            'account_sequence' => 'integer',
            'amount_micro' => 'integer',
            'balance_after_micro' => 'integer',
            'original_entry_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Customer USDT balance ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Customer USDT balance ledger entries are immutable.');
        });
    }

    public function account()
    {
        return $this->belongsTo(CustomerUsdtBalanceAccount::class, 'customer_usdt_balance_account_id');
    }
}
