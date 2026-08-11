<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'payment_id', 'provider', 'state', 'attempt_count', 'provider_response', 'last_error', 'started_at', 'finished_at'])]
class ProviderCancellationAttempt extends Model
{
    use HasFactory;

    public const STATE_PENDING = 'pending';

    public const STATE_PROCESSING = 'processing';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_ALREADY_PAID = 'already_paid';

    public const STATE_UNSUPPORTED = 'unsupported';

    public const STATE_RETRYABLE_FAILURE = 'retryable_failure';

    public const STATE_TERMINAL_FAILURE = 'terminal_failure';

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'provider_response' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
