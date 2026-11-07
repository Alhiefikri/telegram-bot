<?php

namespace App\Services\WijayaPay;

use InvalidArgumentException;

/**
 * The provider publishes an MD5 concatenation signature:
 *
 *   md5(code_merchant + api_key + ref_id)
 *
 * This is not an HMAC, and the callback signature does not cover amount or
 * status. It authenticates the merchant reference claim only, so every
 * successful/expired transition is re-read from the authoritative status
 * endpoint before OrderLifecycle is invoked.
 */
final class WijayaPaySignature
{
    public static function create(string $codeMerchant, string $apiKey, string $refId): string
    {
        self::requiredText($codeMerchant, 'merchant code');
        self::requiredText($apiKey, 'API key');
        self::requiredText($refId, 'merchant reference');

        return md5($codeMerchant.$apiKey.$refId);
    }

    public static function callback(string $codeMerchant, string $apiKey, string $refId): string
    {
        return self::create($codeMerchant, $apiKey, $refId);
    }

    public static function verifyCallback(
        mixed $codeMerchant,
        mixed $apiKey,
        mixed $refId,
        mixed $providedSignature,
    ): bool {
        if (! is_string($codeMerchant)
            || ! is_string($apiKey)
            || ! is_string($refId)
            || ! is_string($providedSignature)
            || $codeMerchant === ''
            || $apiKey === ''
            || $refId === ''
            || $providedSignature === ''
            || trim($codeMerchant) !== $codeMerchant
            || trim($apiKey) !== $apiKey
            || trim($refId) !== $refId
            || trim($providedSignature) !== $providedSignature
            || preg_match('/^[a-f0-9]{32}$/iD', $providedSignature) !== 1
        ) {
            return false;
        }

        try {
            $expected = self::create($codeMerchant, $apiKey, $refId);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals($expected, strtolower($providedSignature));
    }

    private static function requiredText(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('WijayaPay '.$label.' is invalid.');
        }
    }
}
