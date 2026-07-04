<?php

namespace App\Support;

use RuntimeException;

final class PakasirCheckoutFlow
{
    public const HOSTED = 'hosted';

    public const API_QRIS = 'api_qris';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::HOSTED, self::API_QRIS];
    }

    public static function configured(): string
    {
        $flow = config('services.pakasir.checkout_flow', self::HOSTED);

        if (! self::isAllowed($flow)) {
            throw new RuntimeException(self::validationMessage());
        }

        return $flow;
    }

    public static function isAllowed(mixed $flow): bool
    {
        return is_string($flow) && in_array($flow, self::allowed(), true);
    }

    public static function validationMessage(): string
    {
        return 'PAKASIR_CHECKOUT_FLOW must be one of: hosted, api_qris.';
    }
}
