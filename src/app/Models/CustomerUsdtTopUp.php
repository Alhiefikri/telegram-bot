<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerUsdtTopUp extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['intent_id' => 'integer', 'reservation_id' => 'integer', 'customer_id' => 'integer', 'amount_micro' => 'integer', 'fee_micro' => 'integer', 'payable_micro' => 'integer', 'policy_version' => 'integer', 'terms_version' => 'integer', 'receiver_id' => 'integer', 'payment_snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \DomainException('USDT top-up snapshots are immutable.');
        });
        static::deleting(function (): void {
            throw new \DomainException('USDT funding history cannot be deleted.');
        });
    }

    public $incrementing = false;

    protected $keyType = 'string';
}
