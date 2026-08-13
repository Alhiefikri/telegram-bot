<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['broadcast_key', 'source', 'message', 'reply_markup', 'status', 'queue_dispatched_at', 'recipients_materialized_at', 'completed_at', 'recipient_count', 'sent_count', 'failed_count', 'pending_count', 'terminal_failed_count'])]
class TelegramBroadcast extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return [
            'recipients_materialized_at' => 'datetime',
            'completed_at' => 'datetime',
            'reply_markup' => 'array',
            'queue_dispatched_at' => 'datetime',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'pending_count' => 'integer',
            'terminal_failed_count' => 'integer',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TelegramBroadcastRecipient::class, 'broadcast_id');
    }
}
