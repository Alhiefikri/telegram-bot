<?php

namespace App\Models;

use App\Enums\GuestOrderNotificationEvent as GuestOrderNotificationEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'event_type', 'recipient_email', 'status', 'delivery_started_at', 'next_attempt_at', 'sent_at', 'failed_at', 'last_error', 'attempts'])]
class OrderNotificationEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Terminal for credential delivery: the order can no longer receive it. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Recipient snapshots are encrypted and never rendered in full outside delivery. */
    protected $hidden = ['recipient_email'];

    protected function casts(): array
    {
        return [
            'event_type' => GuestOrderNotificationEventType::class,
            'recipient_email' => 'encrypted',
            'delivery_started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
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
