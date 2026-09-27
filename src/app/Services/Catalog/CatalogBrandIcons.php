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
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            static fn (): Collection => CatalogBrandIcon::query()->catalogOrder()->get(),
        );
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
