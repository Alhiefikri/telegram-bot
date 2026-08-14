<?php

namespace App\Services\KlikQris;

use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class KlikQrisTimestamp
{
    public static function parse(mixed $value): CarbonImmutable
    {
        if (! is_scalar($value)) {
            throw new UnexpectedValueException('KlikQRIS timestamp is invalid.');
        }

        $timestamp = trim((string) $value);
        if ($timestamp === '') {
            throw new UnexpectedValueException('KlikQRIS timestamp is invalid.');
        }

        try {
            return CarbonImmutable::parse($timestamp, 'Asia/Jakarta');
        } catch (\Throwable) {
            throw new UnexpectedValueException('KlikQRIS timestamp is invalid.');
        }
    }

    public static function nullable(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : self::parse($value);
    }
}
