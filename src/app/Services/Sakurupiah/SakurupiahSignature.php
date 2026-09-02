<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final class SakurupiahSignature
{
    public static function invoice(
        string $apiId,
        string $method,
        string $merchantRef,
        string $amountSubmitted,
        string $apiKey,
    ): string {
        self::requiredText($apiId, 'API ID');
        self::requiredText($method, 'method');
        self::requiredText($merchantRef, 'merchant reference');
        if (preg_match('/^\d+$/', $amountSubmitted) !== 1) {
            throw new InvalidArgumentException('Sakurupiah submitted amount is invalid.');
        }
        self::requiredText($apiKey, 'API key');

        return hash_hmac('sha256', $apiId.$method.$merchantRef.$amountSubmitted, $apiKey);
    }

    public static function invoiceSignature(
        string $apiId,
        string $method,
        string $merchantRef,
        string $amountSubmitted,
        string $apiKey,
    ): string {
        return self::invoice($apiId, $method, $merchantRef, $amountSubmitted, $apiKey);
    }

    public static function callback(string $rawBody, string $apiKey): string
    {
        self::requiredText($apiKey, 'API key');

        return hash_hmac('sha256', $rawBody, $apiKey);
    }

    public static function callbackSignature(string $rawBody, string $apiKey): string
    {
        return self::callback($rawBody, $apiKey);
    }

    public static function verifyCallback(mixed $rawBody, mixed $providedSignature, mixed $apiKey): bool
    {
        if (! is_string($rawBody) || ! is_string($providedSignature) || ! is_string($apiKey)
            || $providedSignature === ''
            || $apiKey === ''
            || trim($providedSignature) !== $providedSignature
            || trim($apiKey) !== $apiKey
        ) {
            return false;
        }

        $expected = self::callback($rawBody, $apiKey);

        return hash_equals($expected, $providedSignature);
    }

    public static function matchesCallback(mixed $rawBody, mixed $providedSignature, mixed $apiKey): bool
    {
        return self::verifyCallback($rawBody, $providedSignature, $apiKey);
    }

    private static function requiredText(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }
    }
}
