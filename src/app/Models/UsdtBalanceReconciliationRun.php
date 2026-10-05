<?php

namespace App\Models;

use App\Enums\UsdtReconciliationVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Sanitized, append-only evidence of one bounded USDT reconciliation run:
 * exact component totals, the coverage verdict and the (freeze) findings.
 * Downstream operational alerting consumes these rows instead of re-deriving
 * money state; a run never repairs or moves money.
 */
#[Fillable([
    'scope',
    'customer_id',
    'cursor_after',
    'receipt_cursor_after',
    'credit_cursor_after',
    'limit',
    'checked_at',
    'created_at',
    'verdict',
    'accounts_inspected',
    'account_finding_count',
    'drift_frozen_count',
    'receipt_finding_count',
    'liability_micro',
    'unresolved_deposit_micro',
    'custody_state',
    'custody_assets_micro',
    'movement_in_micro',
    'movement_out_micro',
    'movement_count',
    'aggregate_pending_events',
    'actor',
    'findings',
])]
class UsdtBalanceReconciliationRun extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'verdict' => UsdtReconciliationVerdict::class,
            'checked_at' => 'datetime',
            'created_at' => 'datetime',
            'findings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('USDT reconciliation runs are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('USDT reconciliation runs are append-only.');
        });
    }
}
