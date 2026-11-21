<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'payment_id', 'customer_id', 'telegram_user_id', 'chat_id', 'merchant_ref', 'invoice_payload',
    'deadline_epoch', 'policy_version', 'idr_per_usd', 'reward_micro_usd', 'reserve_bps', 'subtotal_idr', 'total_xtr', 'retail_lines',
])]
final class PaymentTelegramStarsDetail extends Model
{
    public $timestamps = false;

    protected $table = 'payment_telegram_stars_details';

    protected $hidden = ['invoice_payload', 'telegram_user_id', 'chat_id'];

    protected static function booted(): void
    {
        $reject = static fn () => throw new LogicException('Telegram Stars checkout snapshots are immutable.');
        self::updating($reject);
        self::deleting($reject);
    }

    protected function casts(): array
    {
        return ['retail_lines' => 'array'] + array_fill_keys([
            'customer_id', 'telegram_user_id', 'chat_id', 'deadline_epoch', 'policy_version',
            'idr_per_usd', 'reward_micro_usd', 'reserve_bps', 'subtotal_idr', 'total_xtr',
        ], 'integer');
    }
}
