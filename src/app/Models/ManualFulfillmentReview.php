<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'payment_id', 'amount', 'currency', 'reason', 'state', 'provider_reference', 'actor_user_id', 'resolved_at'])]
class ManualFulfillmentReview extends Model
{
    use HasFactory;

    public const STATE_PENDING = 'pending';

    public const REASON_LATE_PAYMENT = 'late_payment';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'resolved_at' => 'datetime',
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
