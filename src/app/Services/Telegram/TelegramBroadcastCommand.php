<?php

namespace App\Services\Telegram;

final class TelegramBroadcastCommand
{
    private const PATTERN = '/^\/broadcast(?:\s|$)/u';

    public static function matches(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }

    public static function strip(string $text): string
    {
        return trim((string) preg_replace(self::PATTERN, '', $text, 1));
    }
}
