<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_waitlist_event_id',
    'subscription_id',
    'status',
    'delivery_started_at',
    'sent_at',
    'failed_at',
    'next_attempt_at',
    'attempts',
    'last_error',
])]
class ProductWaitlistNotification extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $hidden = ['last_error'];

    protected function casts(): array
    {
        return [
            'delivery_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProductWaitlistEvent::class, 'product_waitlist_event_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ProductWaitlistSubscription::class, 'subscription_id');
    }
}
