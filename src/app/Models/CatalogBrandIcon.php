<?php

namespace App\Models;

use App\Services\Catalog\CatalogBrandIcons;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-managed brand presentation for the Store Bot catalog: the patterns that
 * recognise a brand in a product or family name, its plain Unicode fallback
 * glyph, and the captured Telegram custom emoji identifier that replaces the
 * glyph on inline keyboard buttons.
 */
#[Fillable(['slug', 'label', 'patterns', 'fallback_emoji', 'custom_emoji_id', 'sort_order'])]
class CatalogBrandIcon extends Model
{
    protected function casts(): array
    {
        return ['patterns' => 'array', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        $forget = static fn () => Cache::forget(CatalogBrandIcons::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @param  Builder<CatalogBrandIcon>  $query
     * @return Builder<CatalogBrandIcon>
     */
    public function scopeCatalogOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label')->orderBy('id');
    }

    public function hasCustomEmoji(): bool
    {
        return trim((string) $this->custom_emoji_id) !== '';
    }

    /**
     * The first matching pattern wins, so earlier brands keep priority exactly
     * like the ordered brand list the resolver used before.
     *
     * @param  string  $normalizedName  a lowercased product or family name
     */
    public function matchesName(string $normalizedName): bool
    {
        foreach ($this->patterns ?? [] as $pattern) {
            $pattern = mb_strtolower(trim((string) $pattern));
            if ($pattern !== '' && str_contains($normalizedName, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
