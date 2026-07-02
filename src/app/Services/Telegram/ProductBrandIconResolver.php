<?php

namespace App\Services\Telegram;

class ProductBrandIconResolver
{
    /**
     * @return array{fallback: string, custom_emoji_id: string|null}
     */
    public function resolve(string $productName): array
    {
        $normalizedName = mb_strtolower($productName);
        $brands = config('telegram-product-icons.brands', []);

        foreach ($brands as $brand) {
            foreach (($brand['patterns'] ?? []) as $pattern) {
                if ($pattern !== '' && str_contains($normalizedName, mb_strtolower((string) $pattern))) {
                    return [
                        'fallback' => (string) ($brand['fallback'] ?? config('telegram-product-icons.default_fallback', '🛍️')),
                        'custom_emoji_id' => $this->customEmojiId($brand['custom_emoji_id'] ?? null),
                    ];
                }
            }
        }

        return [
            'fallback' => (string) config('telegram-product-icons.default_fallback', '🛍️'),
            'custom_emoji_id' => null,
        ];
    }

    private function customEmojiId(mixed $value): ?string
    {
        if (! config('telegram-product-icons.custom_emoji_enabled', true)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
