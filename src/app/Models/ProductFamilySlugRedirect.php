<?php

namespace App\Models;

use Database\Factories\ProductFamilySlugRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['old_slug', 'product_family_id'])]
class ProductFamilySlugRedirect extends Model
{
    /** @use HasFactory<ProductFamilySlugRedirectFactory> */
    use HasFactory;

    public function setOldSlugAttribute(mixed $value): void
    {
        $this->attributes['old_slug'] = is_string($value)
            ? Str::lower(trim($value))
            : $value;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }
}
