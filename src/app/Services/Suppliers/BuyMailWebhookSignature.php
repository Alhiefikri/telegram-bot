<?php

namespace App\Services\Suppliers;

final class BuyMailWebhookSignature
{
    public static function verify(string $secret, string $body, string $header, int $now): bool
    {
        if ($secret === '' || preg_match('/\At=([0-9]{1,11}),v1=([0-9a-f]{64})\z/D', $header, $parts) !== 1
            || abs($now - (int) $parts[1]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts[1].'.'.$body, $secret), $parts[2]);
    }
}
