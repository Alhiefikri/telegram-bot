<?php

namespace App\Models;

use App\Enums\ExternalMoneyReceiptStatus;
use App\Enums\ExternalMoneyReceiptTargetType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'chain_id',
    'tx_hash',
    'external_money_receipt_id',
    'status',
    'target_type',
    'target_id',
    'review_reason',
])]
class ExternalMoneyReceiptParentClaim extends Model
{
    private const IMMUTABLE_ATTRIBUTES = [
        'chain_id',
        'tx_hash',
        'external_money_receipt_id',
        'target_type',
        'target_id',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'status' => ExternalMoneyReceiptStatus::class,
            'target_type' => ExternalMoneyReceiptTargetType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($claim->isDirty($attribute)) {
                    throw new LogicException('External money receipt parent claims keep their original owner and identity.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('External money receipt parent claims are immutable.');
        });
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ExternalMoneyReceipt::class, 'external_money_receipt_id');
    }
}
