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

    public const REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE = 'payment_after_manual_alternative';

    public const REASON_LOCAL_CREDENTIALS_MISSING = 'local_credentials_missing';

    public const REASON_UNIQUE_EMAIL_UNAVAILABLE = 'unique_email_unavailable';

    public const REASON_MANUAL_UNLIMITED = 'manual_unlimited';

    public const REASON_BSC_MISMATCH = 'bsc_mismatch';

    public const REASON_BSC_TRANSACTION_ALREADY_USED = 'bsc_transaction_already_used';

    public const REASON_BSC_AMBIGUOUS = 'bsc_ambiguous';

    public const REASON_BSC_MANUAL_REVIEW = 'bsc_manual_review';

    public function acceptsLocalCredentials(): bool
    {
        return in_array($this->reason, [self::REASON_UNIQUE_EMAIL_UNAVAILABLE, self::REASON_LOCAL_CREDENTIALS_MISSING, self::REASON_MANUAL_UNLIMITED], true);
    }

    public function scopeOwnerNotifiable(Builder $query): void
    {
        $query->where(function (Builder $eligible): void {
            $eligible->whereHas('order', fn (Builder $order) => $order->whereNotNull('api_order_claim_id'))
                ->orWhere('reason', self::REASON_LOCAL_CREDENTIALS_MISSING)
                ->orWhere('reason', self::REASON_UNIQUE_EMAIL_UNAVAILABLE)
                ->orWhere('reason', self::REASON_MANUAL_UNLIMITED)
                ->orWhere('reason', self::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE)
                ->orWhere(function (Builder $late): void {
                    $late->where('reason', self::REASON_LATE_PAYMENT)
                        ->where(function (Builder $items): void {
                            $items->whereHas('order.orderItems', fn (Builder $query) => $query->where('manual_unlimited', true))
                                ->orWhere(function (Builder $legacy): void {
                                    $legacy->whereHas('order.orderItems', fn (Builder $query) => $query->where('quantity', '>', 0))
                                        ->whereDoesntHave('order.orderItems.stockItems')
                                        ->whereDoesntHave('order.orderItems.supplierFulfillment');
                                });
                        });
                });
        });
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('state', [self::STATE_PENDING, self::STATE_REFUND_UNCERTAIN]);
    }

    public function scopeOpenLatePayment(Builder $query): void
    {
        $query->open()->where('reason', self::REASON_LATE_PAYMENT);
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
