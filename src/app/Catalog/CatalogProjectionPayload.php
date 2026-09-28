<?php

namespace App\Catalog;

use InvalidArgumentException;

/**
 * Serialization-safe payload for the catalog read models held by the Telegram
 * catalog projection cache.
 *
 * `config('cache.serializable_classes')` is false, so a value read back from a
 * cache store may only contain scalars and arrays; a cached object arrives as
 * PHP_Incomplete_Class and fails the typed return of its reader. A projection
 * is therefore packed into arrays and rebuilt here, where the allowlist of
 * revivable models lives. Every read model is a value object with public
 * promoted properties, so the payload keeps the property names and the rebuild
 * spreads them back as named arguments instead of maintaining a field map that
 * could drift away from the models.
 */
final class CatalogProjectionPayload
{
    /** @var array<class-string, true> */
    private const MODELS = [
        CatalogBrowseEntry::class => true,
        CatalogBrowseResult::class => true,
        CatalogCategoryOptions::class => true,
        CatalogCategoryReadModel::class => true,
        CatalogFamilyReadModel::class => true,
        CatalogPagination::class => true,
        CatalogProductReadModel::class => true,
    ];

    private const MODEL_KEY = '@model';

    private const PROPERTIES_KEY = '@properties';

    /** @return array<string, mixed> */
    public static function pack(object $model): array
    {
        $class = $model::class;
        if (! isset(self::MODELS[$class])) {
            throw new InvalidArgumentException("{$class} cannot be packed into a catalog projection payload.");
        }

        return [
            self::MODEL_KEY => $class,
            self::PROPERTIES_KEY => array_map(
                static fn (mixed $value): mixed => self::packValue($value),
                get_object_vars($model),
            ),
        ];
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $class
     * @param  array<array-key, mixed>  $payload
     * @return TModel
     */
    public static function unpack(string $class, array $payload): object
    {
        $model = self::unpackValue($payload);
        if (! $model instanceof $class) {
            throw new InvalidArgumentException("The catalog projection payload does not contain a {$class}.");
        }

        return $model;
    }

    private static function packValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return self::pack($value);
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::packValue($item), $value);
        }

        return $value;
    }

    private static function unpackValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $class = $value[self::MODEL_KEY] ?? null;
        if (! is_string($class) || ! isset(self::MODELS[$class])) {
            return array_map(static fn (mixed $item): mixed => self::unpackValue($item), $value);
        }

        $properties = $value[self::PROPERTIES_KEY] ?? null;
        if (! is_array($properties)) {
            throw new InvalidArgumentException("The cached {$class} projection payload is malformed.");
        }

        return new $class(...array_map(
            static fn (mixed $item): mixed => self::unpackValue($item),
            $properties,
        ));
    }
}
