<?php

namespace App\Storefront;

final class StorefrontMediaUrl
{
    public static function fromPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $publicPath = parse_url($path, PHP_URL_PATH);

            return is_string($publicPath) && $publicPath !== '' && ! str_starts_with($publicPath, '//') ? $publicPath : null;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
