<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'status',
    'cursor',
    'scanned_count',
    'claimed_count',
    'replayed_count',
    'reserved_count',
    'conflict_count',
    'review_count',
    'skipped_count',
    'started_at',
    'completed_at',
    'ready_at',
])]
final class ExternalMoneyReceiptBackfillState extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'scanned_count' => 'integer',
            'claimed_count' => 'integer',
            'replayed_count' => 'integer',
            'reserved_count' => 'integer',
            'conflict_count' => 'integer',
            'review_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->completed_at !== null
            && $this->ready_at !== null;
    }
}
