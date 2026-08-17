<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_notification_event_id', 'customer_id', 'recipient_type', 'telegram_id', 'locale', 'text', 'reply_markup', 'claim_expires_at', 'claim_token', 'sent_at', 'failed_at', 'terminal_failed_at', 'last_error', 'attempts'])]
class StockNotificationRecipient extends Model
{
    protected function casts(): array
    {
        return ['reply_markup' => 'array', 'claim_expires_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'terminal_failed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(StockNotificationEvent::class, 'stock_notification_event_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
