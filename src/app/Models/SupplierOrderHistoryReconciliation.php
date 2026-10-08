<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_purchase_id', 'actor_user_id', 'outcome', 'error_code', 'requested_limit',
    'reported_count', 'row_count', 'dispatch_at', 'requested_at', 'observed_at',
    'coverage_start', 'coverage_end', 'history_timezone', 'response_sha256', 'candidate_order_ids',
    'candidate_facts', 'coverage_kind', 'purchase_version', 'purchase_claim_token',
])]
#[Hidden(['response_sha256', 'candidate_order_ids', 'candidate_facts', 'purchase_claim_token'])]
final class SupplierOrderHistoryReconciliation extends Model
{
    public const OUTCOME_NO_ORDER = 'no_order';

    public const OUTCOME_CANDIDATE_ORDERS = 'candidate_orders';

    public const OUTCOME_INCONCLUSIVE = 'inconclusive';

    public const OUTCOME_LIMIT_REACHED = 'limit_reached';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public const OUTCOME_INVALID = 'invalid';

    protected function casts(): array
    {
        return [
            'supplier_purchase_id' => 'integer',
            'actor_user_id' => 'integer',
            'requested_limit' => 'integer',
            'reported_count' => 'integer',
            'row_count' => 'integer',
            'dispatch_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'coverage_start' => 'immutable_datetime',
            'coverage_end' => 'immutable_datetime',
            'candidate_order_ids' => 'encrypted:array',
            'candidate_facts' => 'encrypted:array',
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
}
