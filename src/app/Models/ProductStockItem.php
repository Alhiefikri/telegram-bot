<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'order_item_id', 'retired_at', 'retired_reason', 'secret'])]
class ProductStockItem extends Model
{
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'order_item_id' => 'integer', 'retired_at' => 'datetime', 'secret' => 'encrypted'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
