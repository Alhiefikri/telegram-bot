<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'name', 'description', 'delivery_note', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['category_id' => 'integer', 'price_amount' => 'integer', 'usdt_price_amount' => 'integer', 'stock' => 'integer', 'reserved_stock' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublicVisible(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeStorefrontOrder(Builder $query): Builder
    {
        $productsTable = $query->getModel()->getTable();
        $stockItemsTable = $this->stockItems()->getRelated()->getTable();

        return $query
            ->orderByRaw(
                "CASE WHEN {$productsTable}.stock > {$productsTable}.reserved_stock
                    AND (
                        NOT EXISTS (
                            SELECT 1
                            FROM {$stockItemsTable}
                            WHERE {$stockItemsTable}.product_id = {$productsTable}.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM {$stockItemsTable}
                            WHERE {$stockItemsTable}.product_id = {$productsTable}.id
                                AND {$stockItemsTable}.order_item_id IS NULL
                        )
                    )
                THEN 0 ELSE 1 END"
            )
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeAvailableForPurchase(Builder $query): Builder
    {
        return $query
            ->whereColumn('stock', '>', 'reserved_stock')
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('stockItems')
                ->orWhereHas('stockItems', fn (Builder $stockItems) => $stockItems->whereNull('order_item_id')));
    }

    /**
     * Add the stock projection used by product lists without per-product queries.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithAvailability(Builder $query): Builder
    {
        return $query
            ->withExists('stockItems')
            ->withCount(['stockItems as available_stock_items_count' => fn (Builder $stockItems) => $stockItems->whereNull('order_item_id')]);
    }

    public function availableStock(): int
    {
        $numericAvailable = max(0, $this->stock - $this->reserved_stock);

        if (! $this->isCredentialManaged()) {
            return $numericAvailable;
        }

        return min($numericAvailable, $this->availableStockItemCount());
    }

    public function isAvailable(): bool
    {
        return $this->availableStock() > 0;
    }

    public function isCredentialManaged(): bool
    {
        if (array_key_exists('stock_items_exists', $this->attributes)) {
            return (bool) $this->attributes['stock_items_exists'];
        }

        if ($this->relationLoaded('stockItems')) {
            return $this->stockItems->isNotEmpty();
        }

        return $this->stockItems()->exists();
    }

    public function availableStockItemCount(): int
    {
        if (array_key_exists('available_stock_items_count', $this->attributes)) {
            return (int) $this->attributes['available_stock_items_count'];
        }

        if ($this->relationLoaded('stockItems')) {
            return $this->stockItems->whereNull('order_item_id')->count();
        }

        return $this->stockItems()->whereNull('order_item_id')->count();
    }

    public function isPublicVisible(): bool
    {
        return $this->is_active
            && ! $this->trashed()
            && ($this->category_id === null || ($this->relationLoaded('category')
                ? $this->category !== null && $this->category->is_active
                : $this->category()->where('is_active', true)->exists()));
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function stockItems()
    {
        return $this->hasMany(ProductStockItem::class);
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
