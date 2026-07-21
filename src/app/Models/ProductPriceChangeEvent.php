<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_key', 'product_id', 'product_name', 'old_price_amount', 'new_price_amount', 'old_usdt_price_amount', 'new_usdt_price_amount', 'recipients_materialized_at'])]
class ProductPriceChangeEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'old_price_amount' => 'integer',
            'new_price_amount' => 'integer',
            'old_usdt_price_amount' => 'integer',
            'new_usdt_price_amount' => 'integer',
            'recipients_materialized_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function recipients()
    {
        return $this->hasMany(ProductPriceChangeNotificationRecipient::class, 'event_id');
    }
}
