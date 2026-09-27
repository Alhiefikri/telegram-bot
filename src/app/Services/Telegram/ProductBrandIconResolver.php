<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogIcon;
use App\Models\CatalogBrandIcon;
use App\Services\Catalog\CatalogBrandIcons;

class ProductBrandIconResolver
{
    public function __construct(
        private CatalogBrandIcons $brands,
        private CustomEmojiCapability $customEmoji,
    ) {}

    /**
     * Resolve the catalog icon of a product or family name.
     *
     * Icons are catalog data and are intentionally not read from ENV: admins
     * change them in the Admin Dashboard, and the Store Bot reads the database
     * without rebuilding or clearing configuration.
     */
    public function resolve(string $productName, ?string $configuredIcon = null): CatalogIcon
    {
        $configuredIcon = $this->normalizedIcon($configuredIcon);
        if ($configuredIcon !== null) {
            return new CatalogIcon($configuredIcon);
        }

        $brand = $this->brands->match($productName);
        if (! $brand instanceof CatalogBrandIcon) {
            return new CatalogIcon($this->defaultFallback());
        }

        return new CatalogIcon(
            glyph: $this->normalizedIcon($brand->fallback_emoji) ?? $this->defaultFallback(),
            customEmojiId: $this->customEmojiId($brand),
        );
    }

    private function customEmojiId(CatalogBrandIcon $brand): ?string
    {
        $id = trim((string) $brand->custom_emoji_id);
        if ($id === '' || ! $this->customEmoji->isAvailable()) {
            return null;
        }

        return $id;
    }

    private function defaultFallback(): string
    {
        return (string) config('telegram-product-icons.default_fallback', '🛍️');
    }

    private function normalizedIcon(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
