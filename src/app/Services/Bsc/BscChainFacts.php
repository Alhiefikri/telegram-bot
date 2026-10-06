<?php

namespace App\Services\Bsc;

/** Strict, bounded JSON-RPC quantities; no float or coercion of arrays. */
final class BscChainFacts
{
    public static function quantity(mixed $value): ?int
    {
        if (! is_string($value) || strlen($value) > 18
            || preg_match('/\A0x(?:0|[1-9a-fA-F][0-9a-fA-F]*)\z/', $value) !== 1) {
            return null;
        }
        $number = hexdec(substr($value, 2));

        return is_int($number) && $number >= 0 ? $number : null;
    }

    public static function hash(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 66 ? BscTransactionHash::normalize($value) : null;
    }

    public static function block(?array $block): ?array
    {
        $number = self::quantity($block['number'] ?? null);
        $hash = self::hash($block['hash'] ?? null);
        $timestamp = self::quantity($block['timestamp'] ?? null);
        if ($number === null || $hash === null || $timestamp === null || $timestamp > 253402300799) {
            return null;
        }

        return ['number' => $number, 'hash' => $hash, 'timestamp' => $timestamp];
    }
}
