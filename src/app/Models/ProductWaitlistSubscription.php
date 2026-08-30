<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'email',
    'email_hash',
    'status',
    'confirmation_expires_at',
    'confirmation_sent_at',
    'confirmation_delivery_started_at',
    'confirmed_at',
    'unsubscribed_at',
    'notified_at',
    'confirmation_attempts',
    'confirmation_last_error',
])]
class ProductWaitlistSubscription extends Model
{
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $hidden = ['email', 'email_hash', 'confirmation_last_error'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'confirmation_expires_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmation_delivery_started_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'notified_at' => 'datetime',
            'confirmation_attempts' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ProductWaitlistNotification::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isUnsubscribed(): bool
    {
        return $this->status === self::STATUS_UNSUBSCRIBED;
    }
}
