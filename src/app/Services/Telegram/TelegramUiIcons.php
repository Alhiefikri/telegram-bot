<?php

namespace App\Services\Telegram;

use App\Models\TelegramUiIcon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves captured custom emoji for named navigation slots.
 *
 * The label keeps its own Unicode glyph, so a slot without a captured emoji
 * renders exactly like it did before this feature existed.
 */
class TelegramUiIcons
{
    public const CACHE_KEY = 'telegram-ui-icons';

    private const CACHE_MINUTES = 10;

    public function __construct(private CustomEmojiCapability $customEmoji) {}

    /**
     * @return Collection<int, TelegramUiIcon>
     */
    public function all(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            static fn (): Collection => TelegramUiIcon::query()->catalogOrder()->get(),
        );
    }

    public function customEmojiId(string $key): ?string
    {
        if (! $this->customEmoji->isAvailable()) {
            return null;
        }

        foreach ($this->all() as $icon) {
            if ($icon->key !== $key) {
                continue;
            }

            $id = trim((string) $icon->custom_emoji_id);

            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * Build the text and optional icon field of one navigation button, ready to
     * spread into a button definition.
     *
     * @return array{text: string}|array{text: string, icon_custom_emoji_id: string}
     */
    public function apply(string $key, string $label): array
    {
        $id = $this->customEmojiId($key);
        if ($id === null) {
            return ['text' => $label];
        }

        return [
            'text' => $this->withoutLeadingGlyph($label),
            'icon_custom_emoji_id' => $id,
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function withoutLeadingGlyph(string $label): string
    {
        $stripped = preg_replace('/^[^\p{L}\p{N}]+\s+/u', '', $label);

        return is_string($stripped) && trim($stripped) !== '' ? $stripped : $label;
    }
}
