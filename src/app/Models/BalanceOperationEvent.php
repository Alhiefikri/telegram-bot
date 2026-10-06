<?php

namespace App\Models;

use App\Enums\BalanceOperationMetric;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One append-only, bounded-cardinality operational metric observation. The
 * metric and outcome values come from closed enums; `bucket` is a fixed
 * duration/size label and `value` is a bounded counter (attempt index, row
 * count). No customer, chain, provider or secret value may be stored here.
 * Rows are never updated; DELETE is reserved for the bounded retention prune
 * in `usdt-balance:metrics --prune-days`.
 */
#[Fillable(['metric', 'outcome', 'bucket', 'value', 'occurred_at', 'created_at'])]
class BalanceOperationEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metric' => BalanceOperationMetric::class,
            'value' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Balance operation events are append-only except the bounded retention prune.');
        });
    }
}
