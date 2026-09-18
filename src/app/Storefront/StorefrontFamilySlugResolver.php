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
        $normalized = $this->normalize($slug);
        if ($normalized === '') {
            return null;
        }

        /*
         * A current slug owns the public address even when its family is
         * private or deleted. Never let a colliding alias hijack that address.
         */
        $currentOwner = ProductFamily::withTrashed()
            ->where('slug', $normalized)
            ->first();
        if ($currentOwner instanceof ProductFamily) {
            return $this->current($normalized);
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
