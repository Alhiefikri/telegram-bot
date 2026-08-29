<?php

namespace App\Models;

use App\Enums\GuestOrderNotificationEvent as GuestOrderNotificationEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'event_type', 'status', 'delivery_started_at', 'sent_at', 'failed_at', 'last_error', 'attempts'])]
class OrderNotificationEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'event_type' => GuestOrderNotificationEventType::class,
            'delivery_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
