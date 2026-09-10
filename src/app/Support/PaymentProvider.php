<?php

namespace App\Support;

final class PaymentProvider
{
    public const MANUAL = 'manual';

    public const PAKASIR = 'pakasir';

    public const KLIKQRIS = 'klikqris';

    public const SAKURUPIAH = 'sakurupiah';

    public const STORE_BALANCE = 'store_balance';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::MANUAL, self::KLIKQRIS, self::SAKURUPIAH];
    }

    /** @return list<string> */
    public static function known(): array
    {
        return [self::MANUAL, self::PAKASIR, self::KLIKQRIS, self::SAKURUPIAH, self::STORE_BALANCE];
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
            self::SAKURUPIAH => 'Sakurupiah',
            self::STORE_BALANCE => 'Store Balance',
            default => 'Manual',
        };
    }
}
