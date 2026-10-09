<?php

namespace App\Catalog;

/**
 * Per-locale Product catalog copy: Product Details (`description`) and the
 * Replacement / Refund Policy (`warranty_policy`).
 *
 * The base column of every localizable field keeps the Store default copy and
 * stays the fallback for every locale, so a Product whose variant copy is still
 * empty renders exactly the text it renders today. Variant columns are named
 * `{field}_{locale}` and are the only columns the Admin Dashboard and the
 * Telegram `/admin` editor write for `ms`/`en`.
 */
final class LocalizedProductCopy
{
    /** Customer-facing locale codes, in presentation order. */
    public const LOCALES = ['id', 'ms', 'en'];

    /** Locale whose variant is the field's base column. */
    public const BASE_LOCALE = 'id';

    /** Presentation labels of the editable variants, in `LOCALES` order. */
    public const LOCALE_LABELS = ['id' => 'Bahasa Indonesia', 'ms' => 'Bahasa Melayu', 'en' => 'English'];

    /** Localizable Product copy fields. */
    public const FIELDS = ['description', 'warranty_policy'];

    /** Column holding `$field`'s copy for `$locale`; unsupported locales read the base column. */
    public static function column(string $field, ?string $locale): string
    {
        $locale = self::normalize($locale);

        return $locale === null || $locale === self::BASE_LOCALE
            ? $field
            : $field.'_'.$locale;
    }

    /** Supported locale code, or null when the caller supplied an unknown one. */
    public static function normalize(?string $locale): ?string
    {
        $locale = strtolower(str_replace('_', '-', trim((string) $locale)));
        $locale = explode('-', $locale, 2)[0];

        return in_array($locale, self::LOCALES, true) ? $locale : null;
    }

    /**
     * Copy of `$field` for `$locale`, falling back to the base column when the
     * variant is still empty, with the column that owns the returned text so
     * Telegram premium-emoji metadata is read from the matching variant.
     *
     * @param  array<string, mixed>  $attributes  Product attributes or a catalog read model's copy map.
     * @return array{column: string, text: string}
     */
    public static function resolve(array $attributes, string $field, ?string $locale): array
    {
        $column = self::column($field, $locale);
        $text = (string) ($attributes[$column] ?? '');
        if ($column === $field || trim($text) !== '') {
            return ['column' => $column, 'text' => $text];
        }

        return ['column' => $field, 'text' => (string) ($attributes[$field] ?? '')];
    }

    /** Copy of `$field` for `$locale` with base fallback; `''` when both are empty. */
    public static function text(array $attributes, string $field, ?string $locale): string
    {
        return self::resolve($attributes, $field, $locale)['text'];
    }

    /** Every localizable column, each field's base column first. @return list<string> */
    public static function allColumns(): array
    {
        $columns = [];
        foreach (self::FIELDS as $field) {
            foreach (self::LOCALES as $locale) {
                $columns[] = self::column($field, $locale);
            }
        }

        return $columns;
    }

    /**
     * Raw localizable columns present on `$attributes`, keyed by column, so read
     * models can carry a Product's whole copy without knowing the locale columns.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    public static function extract(array $attributes): array
    {
        $copy = [];
        foreach (self::allColumns() as $column) {
            $value = $attributes[$column] ?? null;
            $copy[$column] = is_scalar($value) ? (string) $value : null;
        }

        return $copy;
    }
}
