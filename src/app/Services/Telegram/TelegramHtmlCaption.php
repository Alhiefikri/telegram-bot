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
        return $this->escape($this->truncate($plainText, $limit));
    }

    public function blockquote(string $plainText): string
    {
        return '<blockquote>'.$this->escape($plainText).'</blockquote>';
    }

    /**
     * Escape a plain-text message while preserving selected plain-text blocks
     * as Telegram HTML blockquotes.
     *
     * @param  list<string>  $blockquoteTexts
     */
    public function htmlWithBlockquotes(
        string $plainText,
        array $blockquoteTexts,
        int $limit = self::LIMIT,
    ): string {
        $plainText = $this->truncate($plainText, $limit);
        $markers = [];

        foreach ($blockquoteTexts as $index => $blockquoteText) {
            $blockquoteText = (string) $blockquoteText;
            if ($blockquoteText === '') {
                continue;
            }

            $position = mb_strpos($plainText, $blockquoteText, 0, 'UTF-8');
            if ($position === false) {
                continue;
            }

            $marker = "\x1Ftelegram-blockquote-{$index}\x1F";
            $plainText = mb_substr($plainText, 0, $position, 'UTF-8')
                .$marker
                .mb_substr($plainText, $position + mb_strlen($blockquoteText, 'UTF-8'), null, 'UTF-8');
            $markers[$marker] = $this->blockquote($blockquoteText);
        }

        return strtr($this->escape($plainText), $markers);
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
            ? html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8')
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

    private function truncate(string $plainText, int $limit): string
    {
        $limit = max(1, $limit);
        if (mb_strlen($plainText, 'UTF-8') <= $limit) {
            return $plainText;
        }

        return mb_substr($plainText, 0, $limit - 1, 'UTF-8').'…';
    }

    private function escape(string $plainText): string
    {
        return htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
