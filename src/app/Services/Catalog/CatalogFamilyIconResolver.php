<?php

namespace App\Services\Catalog;

use App\Catalog\CatalogIconSelection;
use App\Models\Product;

final class CatalogFamilyIconResolver
{
    /**
     * @param  iterable<Product>  $products
     * @return array<int, CatalogIconSelection>
     */
    public function forProducts(iterable $products): array
    {
        $products = collect($products)->values();
        $familyIds = $products
            ->pluck('product_family_id')
            ->filter()
            ->unique()
            ->values();
        $familyMembers = $familyIds->isEmpty()
            ? collect()
            : Product::query()
                ->publicVisible()
                ->whereIn('product_family_id', $familyIds)
                ->orderBy('family_sort_order')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'product_family_id', 'icon', 'custom_emoji_id'])
                ->groupBy('product_family_id');

        $selections = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $members = $product->product_family_id === null
                ? collect([$product])
                : $familyMembers->get($product->product_family_id, collect());

            if ($members->isEmpty()) {
                $members = collect([$product]);
            }

            $selections[(int) $product->getKey()] = $this->fromMembers($members);
        }

        return $selections;
    }

    public function forProduct(Product $product): CatalogIconSelection
    {
        return $this->forProducts([$product])[(int) $product->getKey()]
            ?? $this->fromMembers([$product]);
    }

    /**
     * @param  iterable<array{icon: ?string, custom_emoji_id?: ?string, customEmojiId?: ?string}|Product>  $members
     */
    public function fromMembers(iterable $members): CatalogIconSelection
    {
        $firstGlyph = null;

        foreach ($members as $member) {
            $icon = $this->normalize($member instanceof Product ? $member->icon : ($member['icon'] ?? null));
            $customEmojiId = $this->normalize($member instanceof Product
                ? $member->custom_emoji_id
                : ($member['custom_emoji_id'] ?? $member['customEmojiId'] ?? null));

            if ($customEmojiId !== null) {
                return new CatalogIconSelection($icon, $customEmojiId);
            }

            $firstGlyph ??= $icon;
        }

        return new CatalogIconSelection($firstGlyph, null);
    }

    private function normalize(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
