<?php

namespace App\Services\Telegram;

final class TelegramMessageMedia
{
    public const PHOTO = 'photo';

    public const UNSUPPORTED = 'unsupported';

    /** @var list<string> */
    private const NON_PHOTO_FIELDS = [
        'video',
        'animation',
        'document',
        'audio',
        'voice',
        'sticker',
        'video_note',
        'paid_media',
        'story',
        'contact',
        'location',
        'venue',
        'poll',
        'dice',
        'game',
        'invoice',
        'successful_payment',
    ];

    public static function kind(array $message): ?string
    {
        if (array_key_exists('photo', $message)) {
            return self::PHOTO;
        }

        foreach (self::NON_PHOTO_FIELDS as $field) {
            if (array_key_exists($field, $message)) {
                return $field;
            }
        }

        $marker = $message['media_kind'] ?? null;
        if (is_string($marker) && $marker !== '') {
            return $marker;
        }

        // Telegram captions belong to media-bearing messages. If a newer Bot API
        // media field is not in the inventory above, fail closed instead of
        // silently treating the caption as a text broadcast.
        if (array_key_exists('caption', $message) && is_string($message['caption'])) {
            return self::UNSUPPORTED;
        }

        return null;
    }
}
