<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'supplier_catalog_item_id', 'priority', 'is_active'])]
class ProductSupplierSource extends Model
{
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'supplier_catalog_item_id' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogItem::class, 'supplier_catalog_item_id');
    }
}
