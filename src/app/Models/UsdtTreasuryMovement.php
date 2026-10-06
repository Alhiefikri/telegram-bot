<?php

namespace App\Models;

use App\Enums\UsdtTreasuryMovementDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One audited custody movement recorded by an operator: funds that entered or
 * left the operator's custody pool outside customer funding receipts, such as
 * sweeps, external returns, gas paid from custody or operator reserve moves.
 * The row is bookkeeping evidence only; recording it never signs or sends a
 * transaction. Rows are append-only.
 */
#[Fillable([
    'claim_key',
    'currency',
    'direction',
    'amount_micro',
    'reference',
    'reason',
    'actor',
    'occurred_at',
    'created_at',
])]
class UsdtTreasuryMovement extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'direction' => UsdtTreasuryMovementDirection::class,
            'amount_micro' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('USDT treasury movements are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('USDT treasury movements are append-only.');
        });
    }
}
