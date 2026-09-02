<?php

namespace App\Services\Sakurupiah;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SakurupiahPayloadReader
{
    /**
     * @param  array<mixed>  $data
     */
    public static function object(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new SakurupiahSchemaException;
        }

        return $data;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new SakurupiahSchemaException;
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function requiredString(array $data, string $key, int $maxLength = 255): string
    {
        if (! array_key_exists($key, $data)) {
            throw new SakurupiahSchemaException;
        }

        $value = self::scalarString($data[$key]);
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new SakurupiahSchemaException;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
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
            throw new SakurupiahSchemaException;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function requiredStringOrInteger(array $data, string $key): string|int
    {
        if (! array_key_exists($key, $data)) {
            throw new SakurupiahSchemaException;
        }

        $value = $data[$key];
        if (! is_string($value) && ! is_int($value)) {
            throw new SakurupiahSchemaException;
        }

        if ($value === '' || (is_string($value) && (trim($value) !== $value || strlen($value) > 255))) {
            throw new SakurupiahSchemaException;
        }

        return $value;
    }

    public static function integer(mixed $value, bool $allowZero = true): int
    {
        try {
            return SakurupiahAmount::from($value, allowZero: $allowZero)->value();
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function requiredInteger(array $data, string $key, bool $allowZero = true): int
    {
        if (! array_key_exists($key, $data)) {
            throw new SakurupiahSchemaException;
        }

        return self::integer($data[$key], allowZero: $allowZero);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function optionalInteger(array $data, string $key, bool $allowZero = true): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return self::integer($data[$key], allowZero: $allowZero);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function firstDataItem(array $payload): array
    {
        if (! array_key_exists('data', $payload)) {
            return self::object($payload);
        }

        $items = self::list($payload['data']);
        if ($items === []) {
            throw new SakurupiahSchemaException;
        }

        return self::object($items[0]);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<mixed>
     */
    public static function dataItems(array $payload): array
    {
        if (! array_key_exists('data', $payload)) {
            throw new SakurupiahSchemaException;
        }

        return self::list($payload['data']);
    }

    /**
     * Parse provider-local date/time values in the configured provider timezone.
     * A separate date and time must use the documented Y-m-d and H:i[:s] shapes.
     */
    public static function timestamp(mixed $date, mixed $time = null): CarbonImmutable
    {
        $dateValue = self::scalarString($date);
        if ($dateValue === '' || trim($dateValue) !== $dateValue) {
            throw new SakurupiahSchemaException;
        }

        if ($time !== null) {
            $timeValue = self::scalarString($time);
            if (preg_match('/^\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?$/', $timeValue) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue) !== 1) {
                throw new SakurupiahSchemaException;
            }

            $format = str_contains($timeValue, '.')
                ? 'Y-m-d H:i:s.u'
                : (substr_count($timeValue, ':') === 2 ? 'Y-m-d H:i:s' : 'Y-m-d H:i');

            return self::createFromFormat('!'.$format, $dateValue.' '.$timeValue, self::providerTimezone());
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
            '!Y-m-d',
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

        throw new SakurupiahSchemaException;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function optionalTimestamp(array $data, string $dateKey, ?string $timeKey = null): ?CarbonImmutable
    {
        if (! array_key_exists($dateKey, $data) || $data[$dateKey] === null || $data[$dateKey] === '') {
            if ($timeKey !== null && array_key_exists($timeKey, $data) && $data[$timeKey] !== null && $data[$timeKey] !== '') {
                throw new SakurupiahSchemaException;
            }

            return null;
        }

        return self::timestamp($data[$dateKey], $timeKey === null ? null : ($data[$timeKey] ?? null));
    }

    /**
     * Expiry may be an absolute provider timestamp or an integer hour count
     * relative to the provider-created timestamp. It is invalid without a
     * created timestamp when expressed as a duration.
     */
    public static function optionalExpiry(array $data, string $key, ?CarbonImmutable $createdAt): ?CarbonImmutable
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        $value = $data[$key];
        if (is_int($value) || is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $hours = self::integer($value, allowZero: false);
            if ($createdAt === null) {
                throw new SakurupiahSchemaException;
            }

            return $createdAt->addHours($hours);
        }

        return self::timestamp($value);
    }

    private static function scalarString(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        throw new SakurupiahSchemaException;
    }

    private static function providerTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('services.sakurupiah.timezone', 'Asia/Jakarta'));
        } catch (\Throwable $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    private static function createFromFormat(string $format, string $value, DateTimeZone $timezone): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat($format, $value, $timezone);
        } catch (\Throwable $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }

        $errors = CarbonImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new SakurupiahSchemaException;
        }

        return $parsed;
    }
}
