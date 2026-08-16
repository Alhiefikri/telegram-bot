<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'payment_id', 'amount', 'currency', 'reason', 'resolution_reason', 'state', 'provider_reference', 'refund_provider_reference', 'refund_provider_result', 'refund_requested_at', 'actor_user_id', 'resolved_at'])]
class ManualFulfillmentReview extends Model
{
    use HasFactory;

    public const STATE_PENDING = 'pending';

    public const STATE_FULFILLED = 'fulfilled';

    public const STATE_REFUNDED = 'refunded';

    public const STATE_REJECTED = 'rejected';

    public const STATE_REFUND_UNCERTAIN = 'refund_uncertain';

    public const REASON_LATE_PAYMENT = 'late_payment';

    public function scopeOpenLatePayment(Builder $query): void
    {
        $query
            ->whereIn('state', [self::STATE_PENDING, self::STATE_REFUND_UNCERTAIN])
            ->where('reason', self::REASON_LATE_PAYMENT);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'resolved_at' => 'datetime',
            'refund_requested_at' => 'datetime',
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

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
