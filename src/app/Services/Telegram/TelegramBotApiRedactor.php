<?php

namespace App\Services\Telegram;

final class TelegramBotApiRedactor
{
    private const REDACTED_BOT_API_PATH = '[redacted-telegram-bot-api-path]';

    private const REDACTED_BOT_API_URL = '[redacted-telegram-bot-api-url]';

    private const REDACTED_SECRET = '[redacted-secret]';

    /**
     * @param  list<string>  $additionalSecrets
     */
    public static function redactText(string $text, array $additionalSecrets = []): string
    {
        if ($text === '') {
            return $text;
        }

        $redacted = self::redactBotApiUrls($text);
        $redacted = self::redactBotApiPaths($redacted);
        $redacted = preg_replace(
            '~(?<![A-Za-z0-9_-])(?:wa_confirm(?::|%3A)|wa_)[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])~i',
            self::REDACTED_SECRET,
            $redacted,
        ) ?? $redacted;

        return self::redactSecretValues($redacted, array_merge([
            (string) config('services.telegram.token', ''),
            (string) config('services.telegram.webhook_secret', ''),
        ], $additionalSecrets));
    }

    private static function redactBotApiUrls(string $text): string
    {
        return preg_replace(
            '~https?://[^\s<>"\']+/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?~i',
            self::REDACTED_BOT_API_URL,
            $text,
        ) ?? $text;
    }

    private static function redactBotApiPaths(string $text): string
    {
        return preg_replace(
            '~(?<![A-Za-z0-9])/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?~i',
            self::REDACTED_BOT_API_PATH,
            $text,
        ) ?? $text;
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function redactSecretValues(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret === '' || strlen($secret) < 4) {
                continue;
            }

            foreach (array_unique([$secret, rawurlencode($secret), urlencode($secret)]) as $needle) {
                $text = str_replace($needle, self::REDACTED_SECRET, $text);
            }
        }

        return $text;
    }
}
