<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'product_id', 'product_name', 'delivery_note', 'delivery_note_snapshotted', 'quantity', 'unit_price', 'subtotal'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['delivery_note_snapshotted' => 'boolean', 'quantity' => 'integer', 'unit_price' => 'integer', 'subtotal' => 'integer'];
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
