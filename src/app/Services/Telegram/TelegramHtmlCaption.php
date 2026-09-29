<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogIcon;

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
        return $this->escapeWithCustomEmoji($plainText, $limit);
    }

    public function blockquote(string $plainText): string
    {
        return '<blockquote>'.$this->escapeWithCustomEmoji($plainText, PHP_INT_MAX).'</blockquote>';
    }

    public function icon(CatalogIcon $icon): string
    {
        $glyph = $this->escape($icon->glyph);
        if (! $icon->hasCustomEmoji()) {
            return $glyph;
        }

        return sprintf(
            '<tg-emoji emoji-id="%s" data-omp-icon="1">%s</tg-emoji>',
            $this->escape((string) $icon->customEmojiId),
            $glyph,
        );
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
        $plainText = $this->truncateTextPreservingCustomEmoji($plainText, $limit);
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

        return strtr($this->escapeWithCustomEmoji($plainText, PHP_INT_MAX), $markers);
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

    public function truncateTextPreservingCustomEmoji(string $plainText, int $limit, string $suffix = '…'): string
    {
        $pattern = '~<tg-emoji\\b(?=[^>]*\\bdata-omp-icon="1")[^>]*\\bemoji-id="([^"]+)"[^>]*>([^<]*)</tg-emoji>~u';
        if (preg_match_all($pattern, $plainText, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $this->truncate($plainText, $limit, $suffix);
        }

        $remaining = max(1, $limit);
        $truncated = '';
        $cursor = 0;
        foreach ($matches[0] as $index => [$markup, $byteOffset]) {
            $before = substr($plainText, $cursor, $byteOffset - $cursor);
            $beforeLength = mb_strlen($before, 'UTF-8');
            if ($beforeLength > $remaining) {
                return $truncated.$this->truncate($before, $remaining, $suffix);
            }

            $truncated .= $before;
            $remaining -= $beforeLength;

            $glyph = html_entity_decode($matches[2][$index][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $glyphLength = mb_strlen($glyph, 'UTF-8');
            if ($glyphLength > $remaining) {
                return $truncated.($remaining > 0 ? $suffix : '');
            }

            $truncated .= $markup;
            $remaining -= $glyphLength;
            $cursor = $byteOffset + strlen($markup);
        }

        $tail = substr($plainText, $cursor);
        if ($tail === '' || $remaining === 0) {
            return $truncated;
        }

        return $truncated.$this->truncate($tail, $remaining, $suffix);
    }

    private function escapeWithCustomEmoji(string $plainText, int $limit): string
    {
        $pattern = '~<tg-emoji\\b(?=[^>]*\\bdata-omp-icon="1")[^>]*\\bemoji-id="([^"]+)"[^>]*>([^<]*)</tg-emoji>~u';
        if (preg_match_all($pattern, $plainText, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $this->escape($this->truncate($plainText, $limit));
        }

        $remaining = max(1, $limit);
        $html = '';
        $cursor = 0;
        foreach ($matches[0] as $index => [$markup, $byteOffset]) {
            $before = substr($plainText, $cursor, $byteOffset - $cursor);
            $beforeLength = mb_strlen($before, 'UTF-8');
            if ($beforeLength > $remaining) {
                return $html.$this->escape($this->truncate($before, $remaining));
            }

            $html .= $this->escape($before);
            $remaining -= $beforeLength;

            $glyph = html_entity_decode($matches[2][$index][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $glyphLength = mb_strlen($glyph, 'UTF-8');
            if ($glyphLength > $remaining) {
                return $html.($remaining > 0 ? '…' : '');
            }

            $html .= sprintf(
                '<tg-emoji emoji-id="%s">%s</tg-emoji>',
                $this->escape($matches[1][$index][0]),
                $this->escape($glyph),
            );
            $remaining -= $glyphLength;
            $cursor = $byteOffset + strlen($markup);
        }

        $tail = substr($plainText, $cursor);
        if ($tail === '' || $remaining === 0) {
            return $html;
        }

        return $html.$this->escape($this->truncate($tail, $remaining));
    }

    private function truncate(string $plainText, int $limit, string $suffix = '…'): string
    {
        $limit = max(1, $limit);
        if (mb_strlen($plainText, 'UTF-8') <= $limit) {
            return $plainText;
        }

        $suffixLength = mb_strlen($suffix, 'UTF-8');

        return mb_substr($plainText, 0, max(0, $limit - $suffixLength), 'UTF-8').$suffix;
    }

    private function escape(string $plainText): string
    {
        return htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
