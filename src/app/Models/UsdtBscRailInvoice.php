<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class UsdtBscRailInvoice extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer', 'receiver_id' => 'integer',
            'amount_micro' => 'integer', 'fee_micro' => 'integer', 'payable_micro' => 'integer',
            'chain_id' => 'integer', 'token_decimals' => 'integer',
            'minimum_confirmations' => 'integer', 'requires_finalized_block' => 'boolean',
            'start_block' => 'integer', 'starts_at_epoch' => 'integer',
            'expires_at_epoch' => 'integer', 'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('BSC rail invoices are immutable.'));
        self::deleting(static fn () => throw new LogicException('BSC rail invoices are immutable.'));
    }
}
