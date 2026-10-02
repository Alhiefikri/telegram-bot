<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'external_id', 'name', 'description', 'currency', 'unit_price_micros', 'stock', 'orderable', 'requires_input', 'price_tiers', 'bulk_discounts', 'synced_at'])]
class SupplierCatalogItem extends Model
{
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'unit_price_micros' => 'integer',
            'stock' => 'integer',
            'reserved_quantity' => 'integer',
            'orderable' => 'boolean',
            'requires_input' => 'boolean',
            'price_tiers' => 'array',
            'bulk_discounts' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSupplierSource::class);
    }
}
