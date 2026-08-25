<?php

namespace App\Support;

final class PaymentProvider
{
    public const MANUAL = 'manual';

    public const PAKASIR = 'pakasir';

    public const KLIKQRIS = 'klikqris';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::MANUAL, self::KLIKQRIS];
    }

    /** @return list<string> */
    public static function known(): array
    {
        return [self::MANUAL, self::PAKASIR, self::KLIKQRIS];
    }

    public static function normalize(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        return in_array($provider, self::known(), true) ? $provider : self::MANUAL;
    }

    public static function label(string $provider): string
    {
        return match (self::normalize($provider)) {
            self::PAKASIR => 'Pakasir',
            self::KLIKQRIS => 'KlikQRIS',
            default => 'Manual',
        };
    }
}
