<?php

namespace App\Services\WijayaPay;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Typed reader for the provider's documented response shapes.
 *
 * Values are accepted only when they are unambiguous. Missing identity,
 * malformed money, unknown status and duplicate critical JSON keys fail
 * closed instead of being coerced.
 */
final class WijayaPayPayloadReader
{
    /** @param array<mixed> $data */
    public static function object(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new WijayaPaySchemaException;
        }

        return $data;
    }

    /** @return list<mixed> */
    public static function list(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new WijayaPaySchemaException;
        }

        return $data;
    }

    /** @param array<mixed> $data */
    public static function requiredString(array $data, string $key, int $maxLength = 255): string
    {
        if (! array_key_exists($key, $data)) {
            throw new WijayaPaySchemaException;
        }

        $value = self::scalarString($data[$key]);
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new WijayaPaySchemaException;
        }

        return $value;
    }

    /** @param array<mixed> $data */
    public static function optionalString(array $data, string $key, int $maxLength = 255): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $value = self::scalarString($data[$key]);
        if ($value === '') {
            return null;
        }

        if (trim($value) !== $value || strlen($value) > $maxLength) {
            throw new WijayaPaySchemaException;
        }

        return $value;
    }

    /** @param array<mixed> $data */
    public static function optionalBoolean(array $data, string $key): ?bool
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_bool($data[$key])) {
            throw new WijayaPaySchemaException;
        }

        return $data[$key];
    }

    public static function integer(mixed $value, bool $allowZero = true): int
    {
        try {
            return WijayaPayAmount::from($value, allowZero: $allowZero)->value();
        } catch (InvalidArgumentException $exception) {
            throw new WijayaPaySchemaException(previous: $exception);
        }
    }

    /** @param array<mixed> $data */
    public static function requiredInteger(array $data, string $key, bool $allowZero = false): int
    {
        if (! array_key_exists($key, $data)) {
            throw new WijayaPaySchemaException;
        }

        return self::integer($data[$key], allowZero: $allowZero);
    }

    /** @param array<mixed> $data */
    public static function optionalInteger(array $data, string $key, bool $allowZero = true): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return self::integer($data[$key], allowZero: $allowZero);
    }

    /**
     * The transaction endpoints return a single object under `data`; the
     * channel catalogue returns a list. Never let a list satisfy an object
     * contract or vice versa.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function dataObject(array $payload): array
    {
        if (! array_key_exists('data', $payload)) {
            return self::object($payload);
        }

        return self::object($payload['data']);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<mixed>
     */
    public static function dataItems(array $payload): array
    {
        if (! array_key_exists('data', $payload)) {
            throw new WijayaPaySchemaException;
        }

        return self::list($payload['data']);
    }

    public static function timestamp(mixed $value): CarbonImmutable
    {
        $dateValue = self::scalarString($value);
        if ($dateValue === '' || trim($dateValue) !== $dateValue) {
            throw new WijayaPaySchemaException;
        }

        if (str_ends_with($dateValue, 'Z')) {
            $dateValue = substr($dateValue, 0, -1).'+00:00';
        }

        $formats = [
            '!Y-m-d H:i:s.uP',
            '!Y-m-d\TH:i:s.uP',
            '!Y-m-d H:i:sP',
            '!Y-m-d\TH:i:sP',
            '!Y-m-d H:i:s',
            '!Y-m-d\TH:i:s',
            '!Y-m-d H:i',
            '!Y-m-d\TH:i',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $dateValue, self::providerTimezone());
            } catch (\Throwable) {
                continue;
            }

            $errors = CarbonImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        throw new WijayaPaySchemaException;
    }

    /** @param array<mixed> $data */
    public static function optionalTimestamp(array $data, string $key): ?CarbonImmutable
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return self::timestamp($data[$key]);
    }

    private static function scalarString(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        throw new WijayaPaySchemaException;
    }

    private static function providerTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('services.wijayapay.timezone', 'Asia/Jakarta'));
        } catch (\Throwable $exception) {
            throw new WijayaPaySchemaException(previous: $exception);
        }
    }
}
