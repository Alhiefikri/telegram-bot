<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_purchase_id', 'actor_user_id', 'history_reconciliation_id', 'kind', 'evidence_source', 'provider_reference', 'note', 'observed_at'])]
#[Hidden(['history_reconciliation_id'])]
final class SupplierPurchaseEvidence extends Model
{
    public const KIND_NO_DEBIT = 'no_debit';

    public const KIND_DEBIT_CONFIRMED = 'debit_confirmed';

    protected function casts(): array
    {
        return [
            'supplier_purchase_id' => 'integer',
            'actor_user_id' => 'integer',
            'history_reconciliation_id' => 'integer',
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchase::class, 'supplier_purchase_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function historyReconciliation(): BelongsTo
    {
        return $this->belongsTo(SupplierOrderHistoryReconciliation::class, 'history_reconciliation_id');
    }
}
