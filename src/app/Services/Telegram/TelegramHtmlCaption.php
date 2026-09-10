<?php

namespace App\Services\Telegram;

final class TelegramHtmlCaption
{
    public const LIMIT = 1024;

    /**
     * Telegram limits media captions to 1024 characters after entities parsing.
     * Escaping never changes the parsed length, so the truncated plain text is
     * the parsed caption and every dynamic value stays inert inside the markup.
     */
    public function html(string $plainText, int $limit = self::LIMIT): string
    {
        $limit = max(1, $limit);
        if (mb_strlen($plainText) > $limit) {
            $plainText = mb_substr($plainText, 0, $limit - 1).'…';
        }

        return htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Telegram counts a media caption after entity parsing, so markup escaping
     * must not turn a legal caption into an uneditable one.
     *
     * @param  array<string, mixed>  $options
     */
    public function parsedLength(string $caption, array $options = []): int
    {
        $parsed = ($options['parse_mode'] ?? null) === 'HTML'
            ? html_entity_decode($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $caption;

        return mb_strlen($parsed, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function fitsMediaCaption(string $caption, array $options = []): bool
    {
        return $this->parsedLength($caption, $options) <= self::LIMIT;
    }
}
