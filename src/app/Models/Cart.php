<?php

namespace App\Models;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'origin', 'status', 'expires_at'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $cart): void {
            if ($cart->isDirty('origin')) {
                throw new \LogicException('Cart origin is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => CartStatus::class, 'origin' => CartOrigin::class, 'expires_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function order()
    {
        return $this->hasOne(Order::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
