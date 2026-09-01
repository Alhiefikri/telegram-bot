<?php

namespace App\Services\Telegram;

class ProductBrandIconResolver
{
    /**
     * Resolve an optional Product icon, falling back to a static brand icon.
     *
     * Product icons are catalog data and are intentionally not read from ENV.
     * Admins can change them without rebuilding or clearing configuration.
     */
    public function resolve(string $productName, ?string $configuredIcon = null): string
    {
        $configuredIcon = $this->normalizedIcon($configuredIcon);
        if ($configuredIcon !== null) {
            return $configuredIcon;
        }

        $normalizedName = mb_strtolower($productName);
        foreach (config('telegram-product-icons.brands', []) as $brand) {
            foreach (($brand['patterns'] ?? []) as $pattern) {
                if ($pattern !== '' && str_contains($normalizedName, mb_strtolower((string) $pattern))) {
                    return (string) ($brand['fallback'] ?? config('telegram-product-icons.default_fallback', '🛍️'));
                }
            }
        }

        return (string) config('telegram-product-icons.default_fallback', '🛍️');
    }

    private function normalizedIcon(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
