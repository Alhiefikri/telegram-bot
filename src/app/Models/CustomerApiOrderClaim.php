<?php

namespace App\Models;

use App\Enums\BalancePaymentPreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One durable API create-order claim, uniquely identified by
 * (Customer, operation, Idempotency-Key). Identity and acceptance are
 * immutable; only the fenced lease and the single acceptance transition may
 * change. Successful claims are never pruned.
 */
#[Fillable([
    'customer_id', 'operation', 'idempotency_key', 'fingerprint', 'credential_id', 'state',
    'attempt_token', 'lease_expires_at', 'preference', 'preference_source', 'product_id',
    'quantity', 'expected_idr_total', 'expected_usd_total_micro', 'order_id',
])]
final class CustomerApiOrderClaim extends Model
{
    public const OPERATION_CREATE_ORDER = 'create_order';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_ACCEPTED = 'accepted';

    protected static function booted(): void
    {
        self::updating(function (self $claim): void {
            $frozen = ['customer_id', 'operation', 'idempotency_key', 'fingerprint', 'credential_id',
                'preference', 'preference_source', 'product_id', 'quantity', 'expected_idr_total',
                'expected_usd_total_micro', 'created_at'];
            if ($claim->isDirty($frozen)) {
                throw new LogicException('Customer API order claim identity is immutable.');
            }
            if ($claim->getRawOriginal('state') === self::STATE_ACCEPTED
                && ($claim->isDirty('state') || $claim->isDirty('order_id'))) {
                throw new LogicException('Customer API order claim acceptance is immutable.');
            }
            if ($claim->getRawOriginal('order_id') !== null && $claim->isDirty('order_id')) {
                throw new LogicException('Customer API order claim acceptance is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Customer API order claim history is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'preference' => BalancePaymentPreference::class,
            'quantity' => 'integer',
            'expected_idr_total' => 'integer',
            'expected_usd_total_micro' => 'integer',
        ];
    }
}
