<?php

namespace App\Support;

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

    public static function isAllowed(mixed $flow): bool
    {
        return is_string($flow) && in_array($flow, self::allowed(), true);
    }

    public static function validationMessage(): string
    {
        return 'pakasir_checkout_flow must be one of: hosted, api_qris.';
    }
}
