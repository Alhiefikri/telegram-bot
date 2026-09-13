<?php

namespace App\Models;

use App\Enums\TelegramOutboundEffectState;
use Database\Factories\TelegramOutboundEffectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'effect_key',
    'effect_group_key',
    'supersedes_effect_id',
    'sequence',
    'effect_type',
    'source_type',
    'source_id',
    'target_type',
    'telegram_chat_id',
    'queue',
    'telegram_method',
    'locale',
    'template_version',
    'state',
    'attempts',
    'next_attempt_at',
    'lease_until',
    'claim_token',
    'telegram_message_id',
    'telegram_message_deleted_at',
    'telegram_message_deletion_started_at',
    'telegram_message_deletion_token',
    'unknown_at',
    'failed_at',
    'manual_review_at',
    'last_error_code',
    'last_error',
    'outcome_metadata',
])]
class TelegramOutboundEffect extends Model
{
    /** @use HasFactory<TelegramOutboundEffectFactory> */
    use HasFactory, HasUuids;

    public const CHECKOUT_QUEUE = 'telegram-outbound';

    protected function casts(): array
    {
        return [
            'state' => TelegramOutboundEffectState::class,
            'sequence' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'lease_until' => 'datetime',
            'sent_at' => 'datetime',
            'telegram_message_deleted_at' => 'datetime',
            'telegram_message_deletion_started_at' => 'datetime',
            'unknown_at' => 'datetime',
            'failed_at' => 'datetime',
            'manual_review_at' => 'datetime',
            'outcome_metadata' => 'array',
        ];
    }

    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_effect_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_effect_id');
    }

    protected static function newFactory(): TelegramOutboundEffectFactory
    {
        return TelegramOutboundEffectFactory::new();
    }
}
