<?php

namespace App\Models;

use App\Services\Telegram\TelegramUiIcons;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One named Store Bot navigation slot (menu.home, menu.back, …) that may carry
 * a captured Telegram custom emoji. The plain Unicode glyph stays in the locale
 * copy; this record only owns the optional custom emoji identifier.
 */
#[Fillable(['key', 'label', 'fallback_emoji', 'custom_emoji_id', 'sort_order'])]
class TelegramUiIcon extends Model
{
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        $forget = static fn () => Cache::forget(TelegramUiIcons::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @param  Builder<TelegramUiIcon>  $query
     * @return Builder<TelegramUiIcon>
     */
    public function scopeCatalogOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label')->orderBy('id');
    }

    public function hasCustomEmoji(): bool
    {
        return trim((string) $this->custom_emoji_id) !== '';
    }
}
