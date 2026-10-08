<?php

namespace App\Services\Catalog;

use App\Catalog\CatalogIconSelection;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Support\Collection;

/**
 * Resolves the catalog icon one Product presents. A Product owns an optional
 * per-Product override; a Product without one inherits the icon of the family it
 * belongs to, and a Product with neither keeps the renderer's default glyph.
 * Custom emoji identifiers are captured in Telegram, so this class never parses
 * or renders them: it only selects the stored pair.
 */
final class CatalogFamilyIconResolver
{
    /**
     * @param  iterable<Product>  $products
     * @return array<int, CatalogIconSelection>
     */
    public function forProducts(iterable $products): array
    {
        $products = collect($products)
            ->filter(static fn (mixed $product): bool => $product instanceof Product)
            ->values();

        $families = $this->familiesFor($products);

        $selections = [];
        foreach ($products as $product) {
            $selections[(int) $product->getKey()] = $this->select(
                $product,
                $families->get((int) $product->product_family_id),
            );
        }

        return $selections;
    }

    public function forProduct(Product $product): CatalogIconSelection
    {
        $families = $this->familiesFor(collect([$product]));

        return $this->select($product, $families->get((int) $product->product_family_id));
    }

    /**
     * The icon a family presents for its heading, plan rows, and plan buttons.
     * Only the family owns this value; member overrides never leak into it.
     */
    public function forFamily(ProductFamily $family): CatalogIconSelection
    {
        return new CatalogIconSelection(
            $this->normalize($family->icon),
            $this->normalize($family->custom_emoji_id),
        );
    }

    /**
     * Family icon pairs for the Products that need them, keyed by family id. An
     * already-loaded relation is reused only when it carries the icon columns:
     * a caller that eager-loaded `family:id,name` must not silently drop the
     * icon, so a partial relation is re-read instead.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, ProductFamily>
     */
    private function familiesFor(Collection $products): Collection
    {
        $families = collect();
        $missing = [];

        foreach ($products as $product) {
            if ($this->hasOwnIcon($product) || $product->product_family_id === null) {
                continue;
            }

            $familyId = (int) $product->product_family_id;
            if ($families->has($familyId)) {
                continue;
            }

            $relation = $product->relationLoaded('family') ? $product->getRelation('family') : null;
            if ($relation instanceof ProductFamily && $this->carriesIconColumns($relation)) {
                $families->put($familyId, $relation);

                continue;
            }

            $missing[$familyId] = true;
        }

        if ($missing !== []) {
            ProductFamily::query()
                ->whereIn('id', array_keys($missing))
                ->get(['id', 'icon', 'custom_emoji_id'])
                ->each(function (ProductFamily $family) use ($families): void {
                    $families->put((int) $family->getKey(), $family);
                });
        }

        return $families;
    }

    private function select(Product $product, ?ProductFamily $family): CatalogIconSelection
    {
        if ($this->hasOwnIcon($product)) {
            return $this->ownIcon($product);
        }

        return $family instanceof ProductFamily ? $this->forFamily($family) : $this->ownIcon($product);
    }

    private function ownIcon(Product $product): CatalogIconSelection
    {
        return new CatalogIconSelection(
            $this->normalize($product->icon),
            $this->normalize($product->custom_emoji_id),
        );
    }

    /**
     * A Product with a stored glyph or identifier of its own overrides the whole
     * family pair: a partial mix would render an icon the owner never set.
     */
    private function hasOwnIcon(Product $product): bool
    {
        return $this->normalize($product->icon) !== null || $this->normalize($product->custom_emoji_id) !== null;
    }

    private function carriesIconColumns(ProductFamily $family): bool
    {
        $attributes = $family->getAttributes();

        return array_key_exists('icon', $attributes) && array_key_exists('custom_emoji_id', $attributes);
    }

    private function normalize(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
