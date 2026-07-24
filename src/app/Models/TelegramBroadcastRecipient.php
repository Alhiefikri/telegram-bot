<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['broadcast_id', 'customer_id', 'telegram_id', 'locale', 'send_started_at', 'send_claim_token', 'sent_at', 'failed_at', 'terminal_failed_at', 'last_error', 'attempts'])]
class TelegramBroadcastRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'send_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'terminal_failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(TelegramBroadcast::class, 'broadcast_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
