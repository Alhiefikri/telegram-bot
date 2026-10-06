<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One immutable, append-only version of the customer-facing USDT funding
 * terms. A funding target snapshots the version it was confirmed against, so
 * publishing new terms never rewrites an existing confirmation or invoice.
 */
#[Fillable(['version', 'body', 'created_by_user_id', 'created_at'])]
class CustomerUsdtFundingTerms extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Customer USDT funding terms versions are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Customer USDT funding terms versions are immutable.');
        });
    }
}
