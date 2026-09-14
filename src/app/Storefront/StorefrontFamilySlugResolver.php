<?php

namespace App\Storefront;

use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use Illuminate\Support\Str;

final class StorefrontFamilySlugResolver
{
    public function current(string $slug): ?ProductFamily
    {
        $normalized = $this->normalize($slug);
        if ($normalized === '') {
            return null;
        }

        return ProductFamily::query()
            ->publicVisible()
            ->where('slug', $normalized)
            ->first();
    }

    public function resolvePublic(string $slug): ?ProductFamily
    {
        $current = $this->current($slug);
        if ($current instanceof ProductFamily) {
            return $current;
        }

        $normalized = $this->normalize($slug);
        if ($normalized === '') {
            return null;
        }

        return ProductFamilySlugRedirect::query()
            ->where('old_slug', $normalized)
            ->with(['family' => fn ($query) => $query->publicVisible()])
            ->first()
            ?->family;
    }

    public function normalize(string $slug): string
    {
        return Str::lower(trim($slug));
    }
}
