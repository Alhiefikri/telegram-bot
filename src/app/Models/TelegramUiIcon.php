<?php

namespace App\Models;

use App\Services\Telegram\TelegramUiIcons;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A named Telegram presentation icon slot. Product icon values remain on
 * Product; all shared Store Bot menu and static-copy icons live here.
 */
#[Fillable(['key', 'label', 'fallback_emoji', 'source_emoji', 'custom_emoji_id', 'sort_order'])]
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
