<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'banner_image_path', 'sort_order', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopePublicVisible(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeStorefrontOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
