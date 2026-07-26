<?php

namespace App\Storefront;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StorefrontCatalogSearch
{
    /**
     * Public storefront search is intentionally short because results are a capped
     * catalog browse aid, not a free-form document search surface.
     */
    public const MAX_LENGTH = 80;

    public static function fromRequestQuery(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $search = self::normalize($value);

        if (self::exceedsMaximum($search)) {
            throw new HttpException(422, 'Search query may not be greater than '.self::MAX_LENGTH.' characters.');
        }

        return $search;
    }

    public static function normalize(?string $value): string
    {
        $search = trim((string) $value);

        if ($search === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $search);
        if (is_string($collapsed)) {
            $search = $collapsed;
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($search, 'UTF-8');
        }

        return strtolower($search);
    }

    public static function exceedsMaximum(string $search): bool
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($search, 'UTF-8') > self::MAX_LENGTH;
        }

        return strlen($search) > self::MAX_LENGTH;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function apply(Builder $query, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }

        // Escape LIKE wildcards so a user-supplied "%" or "_" matches literally
        // rather than broadening the scan (mirrors the Telegram catalog search path).
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return $query->where(fn (Builder $query) => $query
            ->whereLike('name', $pattern)
            ->orWhereLike('description', $pattern));
    }
}
