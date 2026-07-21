<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'customer_id', 'telegram_id', 'locale', 'send_started_at', 'send_claim_token', 'sent_at', 'failed_at', 'last_error', 'attempts'])]
class ProductPriceChangeNotificationRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'send_started_at' => 'datetime',
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
}
