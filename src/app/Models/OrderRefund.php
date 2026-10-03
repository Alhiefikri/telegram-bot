<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Hidden(['external_reference', 'external_reference_hash'])]
final class OrderRefund extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'missing_quantity' => 'integer', 'supplier_fulfillment_id' => 'integer', 'external_reference' => 'encrypted', 'confirmed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $refund): void {
            if ($refund->isDirty(['id', 'order_id', 'payment_id', 'customer_id', 'supplier_account_unit_id', 'warranty_claim_id', 'supplier_fulfillment_id', 'missing_quantity', 'amount', 'currency'])) {
                throw new LogicException('Refund economic identity is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Refund history cannot be deleted.'));
    }
}
