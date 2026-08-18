<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['event_id', 'customer_id', 'telegram_id', 'locale', 'sent_at', 'failed_at', 'last_error', 'attempts'])]
class ProductPriceChangeNotificationRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(ProductPriceChangeEvent::class, 'event_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function outboundEffect(): HasOne
    {
        return $this->hasOne(TelegramOutboundEffect::class, 'source_id')
            ->where('source_type', self::class);
    }
}
