<?php

namespace App\Catalog;

/**
 * A catalog icon with a plain Unicode fallback and an optional Telegram custom
 * emoji identifier. HTML message text can render that identifier around the
 * glyph; inline keyboard buttons carry it as their icon.
 */
final readonly class CatalogIcon
{
    public function __construct(
        public string $glyph,
        public ?string $customEmojiId = null,
    ) {}

    public function hasCustomEmoji(): bool
    {
        return $this->customEmojiId !== null && trim($this->customEmojiId) !== '';
    }

    /**
     * A button that carries its icon as a custom emoji must not repeat the
     * glyph in its text, otherwise the customer sees the icon twice.
     */
    public function buttonText(string $label): string
    {
        return $this->hasCustomEmoji() ? $label : $this->glyph.' '.$label;
    }

    /**
     * @return array<string, string>
     */
    public function buttonFields(): array
    {
        return $this->hasCustomEmoji() ? ['icon_custom_emoji_id' => (string) $this->customEmojiId] : [];
    }
}
