<?php

namespace Tests\Support;

final class QrisTestPayload
{
    public static function forAmount(int $amount, string $reference = ''): string
    {
        $value = $amount.'.00';
        $payload = '000201010212530336054'.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value.'5802ID';

        if ($reference !== '') {
            $payload .= '62'.str_pad((string) strlen($reference), 2, '0', STR_PAD_LEFT).$reference;
        }

        $payload .= '6304';

        return $payload.self::crc16($payload);
    }

    public static function officialFee(int $amount): int
    {
        if ($amount <= 105_000) {
            return intdiv(($amount * 70) + 9_999, 10_000) + 310;
        }

        return intdiv(($amount * 100) + 9_999, 10_000);
    }

    private static function crc16(string $value): string
    {
        $crc = 0xFFFF;
        foreach (str_split($value) as $character) {
            $crc ^= ord($character) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
