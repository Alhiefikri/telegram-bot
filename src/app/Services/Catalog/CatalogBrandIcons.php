<?php

namespace App\Services\Catalog;

use App\Models\CatalogBrandIcon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CatalogBrandIcons
{
    public const CACHE_KEY = 'catalog-brand-icons';

    private const CACHE_MINUTES = 10;

    /**
     * @return Collection<int, CatalogBrandIcon>
     */
    public function all(): Collection
    {
        return CatalogBrandIcon::query()->hydrate($this->cachedAttributes());
    }

    /**
     * Cache raw attributes, not Eloquent objects: cache unserialization rejects
     * classes, and a payload written by an older release must never break a
     * read — anything that is not the expected array is rebuilt from the table.
     *
     * @return list<array<string, mixed>>
     */
    private function cachedAttributes(): array
    {
        $attributes = Cache::get(self::CACHE_KEY);
        if (is_array($attributes)) {
            return $attributes;
        }

        $attributes = CatalogBrandIcon::query()
            ->catalogOrder()
            ->get()
            ->map(static fn (CatalogBrandIcon $brand): array => $brand->getAttributes())
            ->values()
            ->all();

        Cache::put(self::CACHE_KEY, $attributes, now()->addMinutes(self::CACHE_MINUTES));

        return $attributes;
    }

    public function match(string $name): ?CatalogBrandIcon
    {
        $normalizedName = mb_strtolower(trim($name));
        if ($normalizedName === '') {
            return null;
        }

        foreach ($this->all() as $brand) {
            if ($brand->matchesName($normalizedName)) {
                return $brand;
            }
        }

        return null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
