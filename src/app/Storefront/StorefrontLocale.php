<?php

namespace App\Storefront;

use App\Catalog\LocalizedProductCopy;

final class StorefrontLocale
{
    public const DEFAULT = LocalizedProductCopy::BASE_LOCALE;

    public const COOKIE = 'storefront_locale';

    public static function preference(mixed $value): ?string
    {
        return in_array($value, LocalizedProductCopy::LOCALES, true) ? $value : null;
    }

    public static function code(): string
    {
        return request()->attributes->get(self::class)
            ?? LocalizedProductCopy::normalize((string) config('storefront.locale', self::DEFAULT))
            ?? self::DEFAULT;
    }
}
