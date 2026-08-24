<?php

namespace App\Services\Bsc;

use kornrunner\Keccak;

final class BscEip191
{
    public static function messageHash(string $message): string
    {
        return Keccak::hash(
            "\x19Ethereum Signed Message:\n".strlen($message).$message,
            256,
            true,
        );
    }

    public static function messageHashHex(string $message): string
    {
        return Keccak::hash(
            "\x19Ethereum Signed Message:\n".strlen($message).$message,
            256,
        );
    }
}
