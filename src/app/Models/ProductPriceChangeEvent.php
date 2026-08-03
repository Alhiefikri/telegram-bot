<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_key', 'product_id', 'product_name', 'old_price_amount', 'new_price_amount', 'old_usdt_price_amount', 'new_usdt_price_amount', 'recipients_materialized_at', 'store_channel_send_started_at', 'store_channel_send_claim_token', 'store_channel_sent_at', 'store_channel_failed_at', 'store_channel_last_error', 'store_channel_attempts', 'store_channel_message_id'])]
class ProductPriceChangeEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'old_price_amount' => 'integer',
            'new_price_amount' => 'integer',
            'old_usdt_price_amount' => 'integer',
            'new_usdt_price_amount' => 'integer',
            'recipients_materialized_at' => 'datetime',
            'store_channel_send_started_at' => 'datetime',
            'store_channel_sent_at' => 'datetime',
            'store_channel_failed_at' => 'datetime',
            'store_channel_attempts' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function recipients()
    {
        return $this->hasMany(ProductPriceChangeNotificationRecipient::class, 'event_id');
    }
}
