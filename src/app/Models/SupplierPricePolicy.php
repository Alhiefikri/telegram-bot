<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'mode', 'markup_basis_points', 'profit_idr', 'minimum_profit_idr', 'rounding_idr'])]
class SupplierPricePolicy extends Model
{
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'markup_basis_points' => 'integer', 'profit_idr' => 'integer', 'minimum_profit_idr' => 'integer', 'rounding_idr' => 'integer', 'version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
