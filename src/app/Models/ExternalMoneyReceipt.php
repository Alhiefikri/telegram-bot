<?php

namespace App\Models;

use App\Enums\ExternalMoneyReceiptStatus;
use App\Enums\ExternalMoneyReceiptTargetType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'canonical_key',
    'provider',
    'identity_kind',
    'account_namespace',
    'reference',
    'chain_id',
    'tx_hash',
    'log_index',
    'beneficiary',
    'currency',
    'amount_units',
    'decimals',
    'status',
    'target_type',
    'target_id',
    'fingerprint',
    'review_reason',
    'evidence_payload',
    'claimed_at',
])]
class ExternalMoneyReceipt extends Model
{
    public const REVIEW_REASON_BSC_PARENT_CROSS_TARGET = 'bsc_parent_transaction_cross_target';

    private const IMMUTABLE_ATTRIBUTES = [
        'canonical_key',
        'provider',
        'identity_kind',
        'account_namespace',
        'reference',
        'chain_id',
        'tx_hash',
        'log_index',
        'beneficiary',
        'currency',
        'amount_units',
        'decimals',
        'fingerprint',
        'target_type',
        'target_id',
        'evidence_payload',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'log_index' => 'integer',
            'decimals' => 'integer',
            'status' => ExternalMoneyReceiptStatus::class,
            'target_type' => ExternalMoneyReceiptTargetType::class,
            'evidence_payload' => 'array',
            'claimed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($receipt->isDirty($attribute)) {
                    throw new LogicException('External money receipts are immutable once observed.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('External money receipts are immutable once observed.');
        });
    }
}
