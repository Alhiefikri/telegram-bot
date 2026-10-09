<?php

namespace App\Storefront;

use App\Catalog\LocalizedProductCopy;

/**
 * Locale the Web Storefront renders in. It is one Admin-visible setting for the
 * whole store (the stores default is `id`), not a per-visitor negotiation, so
 * localized Product copy always resolves against this single code.
 */
final class StorefrontLocale
{
    public const DEFAULT = LocalizedProductCopy::BASE_LOCALE;

    /** Validated `storefront.locale` code, falling back to the Store default. */
    public static function code(): string
    {
        return LocalizedProductCopy::normalize((string) config('storefront.locale', self::DEFAULT)) ?? self::DEFAULT;
    }
}
