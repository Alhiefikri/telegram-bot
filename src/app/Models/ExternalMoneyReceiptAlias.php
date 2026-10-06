<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'external_money_receipt_id',
    'alias_key',
    'alias_type',
    'created_at',
])]
class ExternalMoneyReceiptAlias extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('External money receipt aliases are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('External money receipt aliases are immutable.');
        });
    }
}
