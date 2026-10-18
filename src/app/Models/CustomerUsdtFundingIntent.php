<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerUsdtFundingIntent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['nonce'];

    protected function casts(): array
    {
        return ['telegram_navigation_root_id' => 'integer', 'canonical_message_id' => 'integer', 'root_generation' => 'integer', 'version' => 'integer', 'claimed_at' => 'immutable_datetime', 'customer_id' => 'integer', 'amount_micro' => 'integer', 'fee_micro' => 'integer', 'payable_micro' => 'integer', 'policy_version' => 'integer', 'terms_version' => 'integer', 'receiver_id' => 'integer', 'shared_receiver_id' => 'integer', 'payment_snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal('state') !== 'pending' || $model->state === 'pending' || $model->version !== $model->getOriginal('version') + 1 || $model->claimed_at === null || array_diff(array_keys($model->getDirty()), ['state', 'version', 'claimed_at']) !== []) {
                throw new \DomainException('USDT intent identity and terminal state are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \DomainException('USDT funding history cannot be deleted.');
        });
    }
}
