<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'product_id', 'product_name', 'product_icon', 'product_custom_emoji_id', 'family_id', 'family_name', 'delivery_note', 'delivery_note_snapshotted', 'usage_guide_title', 'usage_guide_url', 'usage_guide_snapshotted', 'quantity', 'local_quantity', 'supplier_quantity', 'quantity_price_tier_minimum', 'unit_price', 'usdt_unit_price', 'subtotal', 'usdt_subtotal'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $hidden = ['local_quantity', 'supplier_quantity', 'supplierFulfillment', 'supplier_fulfillment'];

    public function localQuantity(): int
    {
        return $this->local_quantity ?? $this->quantity;
    }

    public function supplierFulfillment()
    {
        return $this->hasOne(SupplierFulfillment::class);
    }

    protected function casts(): array
    {
        return ['local_quantity' => 'integer', 'supplier_quantity' => 'integer', 'family_id' => 'integer', 'quantity_price_tier_minimum' => 'integer', 'delivery_note_snapshotted' => 'boolean', 'usage_guide_snapshotted' => 'boolean', 'quantity' => 'integer', 'unit_price' => 'integer', 'usdt_unit_price' => 'integer', 'subtotal' => 'integer', 'usdt_subtotal' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stockItems()
    {
        return $this->hasMany(ProductStockItem::class);
    }
}
