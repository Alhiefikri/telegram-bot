<?php

namespace App\Models;

use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'image_path', 'sort_order'])]
class ProductFamily extends Model
{
    /** @use HasFactory<ProductFamilyFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * A family inherits public visibility from at least one public-visible
     * member Product; it has no independent sale-visibility flag.
     *
     * @param  Builder<ProductFamily>  $query
     * @return Builder<ProductFamily>
     */
    public function scopePublicVisible(Builder $query): Builder
    {
        return $query->whereHas('products', fn (Builder $products): Builder => $products->publicVisible());
    }

    /**
     * @param  Builder<ProductFamily>  $query
     * @return Builder<ProductFamily>
     */
    public function scopeCatalogOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Alias matching Product and Category storefront ordering conventions.
     *
     * @param  Builder<ProductFamily>  $query
     * @return Builder<ProductFamily>
     */
    public function scopeStorefrontOrder(Builder $query): Builder
    {
        return $this->scopeCatalogOrder($query);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_family_id')->orderBy('family_sort_order')->orderBy('name')->orderBy('id');
    }

    /**
     * Alias for callers that use the catalog term for family members.
     *
     * @return HasMany<Product, $this>
     */
    public function members(): HasMany
    {
        return $this->products();
    }

    public function getAuditSubjectIdAttribute(): string
    {
        return (string) $this->getKey();
    }
}
