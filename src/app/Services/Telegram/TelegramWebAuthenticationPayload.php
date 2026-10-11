<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

final class TelegramWebAuthenticationPayload
{
    private const ENCRYPTED_FIELD = '_encrypted_web_auth';

    public static function isMessage(#[\SensitiveParameter] array $message): bool
    {
        $text = $message['text'] ?? null;

        return is_string($text)
            && preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?\s+wa_/', trim($text)) === 1;
    }

    public static function isCallback(#[\SensitiveParameter] array $callback): bool
    {
        $data = $callback['data'] ?? null;

        return is_string($data) && str_starts_with($data, 'wa_confirm:');
    }

    public static function forStorage(#[\SensitiveParameter] array $payload): array
    {
        if (! self::isMessage($payload['message'] ?? []) && ! self::isCallback($payload['callback_query'] ?? [])) {
            return $payload;
        }

        return [
            'update_id' => $payload['update_id'],
            self::ENCRYPTED_FIELD => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function forProcessing(array $payload): array
    {
        if (! array_key_exists(self::ENCRYPTED_FIELD, $payload)) {
            return $payload;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload[self::ENCRYPTED_FIELD]), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Telegram web authentication payload is unavailable.');
        }
        if (! is_array($decoded) || ($decoded['update_id'] ?? null) !== ($payload['update_id'] ?? null)) {
            throw new RuntimeException('Telegram web authentication payload is unavailable.');
        }

        return $decoded;
    }
}
