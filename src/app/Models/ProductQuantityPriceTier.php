<?php

namespace App\Models;

use Database\Factories\ProductQuantityPriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['product_id', 'minimum_quantity', 'price_amount', 'usdt_price_amount'])]
class ProductQuantityPriceTier extends Model
{
    /** @use HasFactory<ProductQuantityPriceTierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'minimum_quantity' => 'integer',
            'price_amount' => 'integer',
            'usdt_price_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tier): void {
            if ($tier->minimum_quantity < 2 || $tier->minimum_quantity > 20) {
                throw new InvalidArgumentException('Quantity Price Tier minimum quantity must be between 2 and 20.');
            }

            if ($tier->price_amount <= 0) {
                throw new InvalidArgumentException('Quantity Price Tier IDR price must be positive.');
            }

            if ($tier->usdt_price_amount !== null && $tier->usdt_price_amount <= 0) {
                throw new InvalidArgumentException('Quantity Price Tier USDT price must be positive when present.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
