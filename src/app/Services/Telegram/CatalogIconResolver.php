<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogIcon;

/**
 * Resolves the catalog icon of one Product: its plain Unicode fallback plus an
 * optional captured Telegram custom emoji identifier. HTML message text may
 * render that identifier around the glyph, while inline keyboard buttons carry
 * it as their icon.
 *
 * Icons are catalog data owned by each Product in the database. Nothing is
 * inferred from a Product name, so a Product without its own icon simply keeps
 * the shared default glyph. Icons are never read from ENV.
 */
class CatalogIconResolver
{
    public function __construct(private CustomEmojiCapability $customEmoji) {}

    public function resolve(
        ?string $glyph,
        ?string $customEmojiId = null,
        string $scope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): CatalogIcon {
        return new CatalogIcon(
            glyph: $this->normalized($glyph) ?? $this->defaultFallback(),
            customEmojiId: $this->customEmojiId($customEmojiId, $scope),
        );
    }

    private function customEmojiId(?string $value, string $scope): ?string
    {
        $id = $this->normalized($value);
        if ($id === null || ! $this->customEmoji->isAvailable($scope)) {
            return null;
        }

        return $id;
    }

    private function defaultFallback(): string
    {
        return (string) config('telegram-product-icons.default_fallback', '🛍️');
    }

    private function normalized(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
