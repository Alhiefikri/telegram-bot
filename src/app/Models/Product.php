<?php

namespace App\Models;

use App\Services\AuditLogger;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'price_amount', 'currency', 'image_path', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['price_amount' => 'integer', 'stock' => 'integer', 'reserved_stock' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Product $product) => app(AuditLogger::class)->log('product.deleted', $product, $product->getOriginal(), null, auth()->user(), request()?->ip()));
        static::restored(fn (Product $product) => app(AuditLogger::class)->log('product.restored', $product, null, $product->only(['id', 'name']), auth()->user(), request()?->ip()));
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'subject_id', 'audit_subject_id')->where('subject_type', self::class);
    }

    public function getAuditSubjectIdAttribute(): string
    {
        return (string) $this->getKey();
    }
}
