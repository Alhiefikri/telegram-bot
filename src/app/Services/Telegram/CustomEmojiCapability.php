<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Custom emoji entities in bot messages and inline keyboard button icons
 * require Telegram to allow that bot to use them. Message and channel
 * rejections are remembered separately, so one unsupported destination does
 * not suppress custom emoji where Telegram still permits them.
 */
class CustomEmojiCapability
{
    public const CACHE_KEY = 'telegram.custom-emoji-unavailable';

    public const CHANNEL_CACHE_KEY = 'telegram.custom-emoji-channel-unavailable';

    public const SCOPE_MESSAGES = 'messages';

    public const SCOPE_CHANNEL = 'channel';

    /**
     * Every wrapper a renderer can produce, including catalog wrappers written
     * by TelegramProductContent/TelegramHtmlCaption. Those carry no internal
     * marker; a plain-text send still has to consume them.
     */
    private const ANY_WRAPPER_PATTERN = '~<tg-emoji\b[^>]*>([^<]*)</tg-emoji>~u';

    /** Captures the identifier of an already matched wrapper opener. */
    private const WRAPPER_ID_PATTERN = '~<tg-emoji\b[^>]*\bemoji-id="([^"]+)"~';

    private const RETRY_AFTER_MINUTES = 30;

    public function isAvailable(string $scope = self::SCOPE_MESSAGES): bool
    {
        return ! Cache::has($this->cacheKey($scope));
    }

    public function unavailableReason(string $scope = self::SCOPE_MESSAGES): ?string
    {
        $reason = Cache::get($this->cacheKey($scope));

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function markUnavailable(?string $reason, string $scope = self::SCOPE_MESSAGES): void
    {
        Cache::put($this->cacheKey($scope), (string) $reason, now()->addMinutes(self::RETRY_AFTER_MINUTES));
    }

    public function markAvailable(?string $scope = null): void
    {
        if ($scope === null) {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::CHANNEL_CACHE_KEY);

            return;
        }

        Cache::forget($this->cacheKey($scope));
    }

    private function cacheKey(string $scope): string
    {
        return $scope === self::SCOPE_CHANNEL ? self::CHANNEL_CACHE_KEY : self::CACHE_KEY;
    }

    /**
     * Remove every custom emoji icon from a reply markup, keeping the rest of
     * the button definition untouched.
     *
     * @param  array<array-key, mixed>  $replyMarkup
     * @return array<array-key, mixed>
     */
    public function stripIcons(array $replyMarkup): array
    {
        return $this->stripIconFields($replyMarkup);
    }

    public function stripEncodedIcons(string $replyMarkup): string
    {
        $decoded = json_decode($replyMarkup, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) && ! is_object($decoded)) {
            return $replyMarkup;
        }

        return json_encode($this->stripIconFields($decoded), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>|object  $markup
     * @return array<array-key, mixed>|object
     */
    private function stripIconFields(array|object $markup): array|object
    {
        foreach ($markup as $key => $value) {
            if ($key === 'icon_custom_emoji_id') {
                if (is_object($markup)) {
                    unset($markup->{$key});
                } else {
                    unset($markup[$key]);
                }

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $cleaned = $this->stripIconFields($value);
                if (is_object($markup)) {
                    $markup->{$key} = $cleaned;
                } else {
                    $markup[$key] = $cleaned;
                }
            }
        }

        return $markup;
    }

    /**
     * Convert Telegram custom-emoji HTML wrappers to UTF-16-offset message
     * entities without enabling HTML parsing for otherwise plain copy.
     *
     * Every wrapper is consumed: a trusted registry wrapper becomes a custom
     * emoji entity, and a catalog wrapper without the internal marker keeps its
     * glyph. A plain-text payload must never carry literal `<tg-emoji>` markup,
     * because Telegram does not parse it and the customer would read it.
     *
     * @param  list<array<string, mixed>>  $entities
     * @return array{text: string, entities: list<array<string, mixed>>}
     */
    public function textAndEntities(string $text, array $entities = []): array
    {
        if (! str_contains($text, '<tg-emoji')) {
            return ['text' => $text, 'entities' => $entities];
        }

        $pattern = '~<tg-emoji\b(?=[^>]*\bdata-omp-icon="1")[^>]*\bemoji-id="([^"]+)"[^>]*>([^<]*)</tg-emoji>'
            .'|'
            .'<tg-emoji\b[^>]*>([^<]*)</tg-emoji>~u';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return ['text' => $text, 'entities' => $entities];
        }

        $rendered = '';
        $cursor = 0;
        $removals = [];
        $added = [];
        foreach ($matches[0] as $index => [$markup, $byteOffset]) {
            $markupStart = $this->utf16Length(substr($text, 0, $byteOffset));
            $markupUnits = $this->utf16Length($markup);
            foreach ($entities as $entity) {
                if (($entity['type'] ?? null) === 'pre'
                    && $markupStart < $entity['offset'] + $entity['length']
                    && $markupStart + $markupUnits > $entity['offset']) {
                    continue 2;
                }
            }
            $rendered .= substr($text, $cursor, $byteOffset - $cursor);
            // Group 1/2 belong to the trusted-marked alternative, group 3 to the
            // catalog wrapper without the internal marker.
            $id = $matches[1][$index][0];
            $glyphSource = $matches[3][$index][0];
            if ($id === '') {
                preg_match(self::WRAPPER_ID_PATTERN, $markup, $opener);
                $id = (string) ($opener[1] ?? '');
            } else {
                $glyphSource = $matches[2][$index][0];
            }
            $glyph = html_entity_decode($glyphSource, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $offset = $this->utf16Length($rendered);
            $rendered .= $glyph;
            if ($id !== '') {
                $added[] = [
                    'type' => 'custom_emoji',
                    'offset' => $offset,
                    'length' => $this->utf16Length($glyph),
                    'custom_emoji_id' => $id,
                ];
            }
            $removals[] = [
                'start' => $markupStart,
                'end' => $markupStart + $markupUnits,
                'delta' => $markupUnits - $this->utf16Length($glyph),
            ];
            $cursor = $byteOffset + strlen($markup);
        }
        $rendered .= substr($text, $cursor);

        $entities = $this->sortEntities([
            ...$this->reoffsetEntities($entities, $removals),
            ...$added,
        ]);

        return ['text' => $rendered, 'entities' => $entities];
    }

    /**
     * Rebase caller-supplied entity offsets after custom-emoji markup is removed.
     *
     * Copyable values such as a Binance receiver or amount are located on the
     * pre-replacement text, so every entity that sits after a removed tag must
     * move back by the removed length. Entities that would land inside or out of
     * range are dropped instead of sending an offset Telegram cannot parse.
     *
     * @param  list<array<string, mixed>>  $entities
     * @param  list<array{start: int, end: int, delta: int}>  $removals
     * @return list<array<string, mixed>>
     */
    private function reoffsetEntities(array $entities, array $removals): array
    {
        if ($removals === [] || $entities === []) {
            return $this->sortEntities($entities);
        }

        $shifted = [];
        foreach ($entities as $entity) {
            if (! isset($entity['offset'], $entity['length'])) {
                $shifted[] = $entity;

                continue;
            }

            $offset = (int) $entity['offset'];
            $length = (int) $entity['length'];
            $delta = 0;
            $overlap = false;
            foreach ($removals as $removal) {
                if ($removal['end'] <= $offset) {
                    $delta += $removal['delta'];
                } elseif ($removal['start'] < $offset + $length) {
                    $overlap = true;

                    break;
                }
            }

            if ($overlap || $offset - $delta < 0) {
                continue;
            }

            $entity['offset'] = $offset - $delta;
            $shifted[] = $entity;
        }

        return $this->sortEntities($shifted);
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @return list<array<string, mixed>>
     */
    private function sortEntities(array $entities): array
    {
        $entities = array_values($entities);
        usort($entities, static fn (array $left, array $right): int => (int) ($left['offset'] ?? 0) <=> (int) ($right['offset'] ?? 0));

        return $entities;
    }

    /**
     * Remove only custom-emoji entities from a Telegram entity list.
     *
     * @param  list<array<string, mixed>>|string  $entities
     * @return list<array<string, mixed>>|string
     */
    public function stripTextCustomEmojiEntities(array|string $entities): array|string
    {
        $decoded = is_string($entities) ? json_decode($entities, true) : $entities;
        if (! is_array($decoded)) {
            return $entities;
        }

        $filtered = array_values(array_filter(
            $decoded,
            static fn (mixed $entity): bool => ! is_array($entity) || ($entity['type'] ?? null) !== 'custom_emoji',
        ));

        return is_string($entities) ? json_encode($filtered, JSON_THROW_ON_ERROR) : $filtered;
    }

    private function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Inline keyboard buttons render their icon through `icon_custom_emoji_id`;
     * Telegram does not parse markup inside button text. Move the first trusted
     * custom emoji of every button label into that field — dropping its glyph so
     * the icon is not shown twice — and degrade any later wrapper to its plain
     * glyph, so a button built from rendered copy keeps the captured premium
     * emoji instead of silently falling back to the default.
     *
     * @param  array<string, mixed>|string  $replyMarkup
     * @return array<string, mixed>|string
     */
    public function iconsFromReplyMarkup(array|string $replyMarkup): array|string
    {
        $markup = is_string($replyMarkup) ? json_decode($replyMarkup) : $replyMarkup;
        if (! is_array($markup) && ! is_object($markup)) {
            return $replyMarkup;
        }

        $markup = $this->moveButtonIcons($markup);

        return is_string($replyMarkup) ? json_encode($markup, JSON_THROW_ON_ERROR) : $markup;
    }

    /**
     * @param  array<array-key, mixed>|object  $markup
     * @return array<array-key, mixed>|object
     */
    private function moveButtonIcons(array|object $markup): array|object
    {
        foreach ($markup as $key => $value) {
            if ($key === 'text' && is_string($value)) {
                $id = $this->firstTextCustomEmojiId($value);
                if ($id !== null && ! $this->hasButtonIcon($markup)) {
                    if (is_array($markup)) {
                        $markup['icon_custom_emoji_id'] = $id;
                    } else {
                        $markup->icon_custom_emoji_id = $id;
                    }
                    $value = trim($this->stripTextCustomEmoji($this->dropFirstTextCustomEmoji($value)));
                } else {
                    $value = $this->stripTextCustomEmoji($value);
                }

                if (is_array($markup)) {
                    $markup[$key] = $value;
                } else {
                    $markup->{$key} = $value;
                }

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $normalized = $this->moveButtonIcons($value);
                if (is_array($markup)) {
                    $markup[$key] = $normalized;
                } else {
                    $markup->{$key} = $normalized;
                }
            }
        }

        return $markup;
    }

    private function firstTextCustomEmojiId(string $text): ?string
    {
        return preg_match('~<tg-emoji\\b(?=[^>]*\\bdata-omp-icon="1")[^>]*\\bemoji-id="([^"]+)"[^>]*>~u', $text, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function dropFirstTextCustomEmoji(string $text): string
    {
        return preg_replace('~<tg-emoji\\b(?=[^>]*\\bdata-omp-icon="1")[^>]*>.*?</tg-emoji>~si', '', $text, 1) ?? $text;
    }

    /**
     * @param  array<array-key, mixed>|object  $markup
     */
    private function hasButtonIcon(array|object $markup): bool
    {
        if (is_array($markup)) {
            return isset($markup['icon_custom_emoji_id']);
        }

        return isset($markup->icon_custom_emoji_id);
    }

    /**
     * Remove Telegram custom-emoji HTML wrappers while keeping their fallback
     * glyphs. Every wrapper is removed, because a plain-text payload renders
     * markup literally and a rejected custom emoji must degrade to its glyph.
     */
    public function stripTextCustomEmoji(string $html): string
    {
        return preg_replace(self::ANY_WRAPPER_PATTERN, '$1', $html) ?? $html;
    }

    public function stripHtmlCustomEmoji(string $html): string
    {
        return preg_replace('~<tg-emoji\\b[^>]*>(.*?)</tg-emoji>~si', '$1', $html) ?? $html;
    }

    public function canonicalizeTrustedCustomEmojiHtml(string $html): string
    {
        return preg_replace_callback(
            '~<tg-emoji\\b(?=[^>]*\\bdata-omp-icon="1")[^>]*\\bemoji-id="([^"]+)"[^>]*>(.*?)</tg-emoji>~si',
            static fn (array $match): string => '<tg-emoji emoji-id="'.$match[1].'">'.$match[2].'</tg-emoji>',
            $html,
        ) ?? $html;
    }

    /**
     * Inline keyboard button text has no custom-emoji entity support. Preserve
     * its configured Unicode fallback while removing registry markup.
     *
     * @param  array<string, mixed>|string  $replyMarkup
     * @return array<string, mixed>|string
     */
    public function stripTextCustomEmojiFromReplyMarkup(array|string $replyMarkup): array|string
    {
        $markup = is_string($replyMarkup) ? json_decode($replyMarkup) : $replyMarkup;
        if (! is_array($markup) && ! is_object($markup)) {
            return $replyMarkup;
        }

        $stripText = function (array|object $value) use (&$stripText): array|object {
            foreach ($value as $key => $item) {
                if ($key === 'text' && is_string($item)) {
                    if (is_array($value)) {
                        $value[$key] = $this->stripTextCustomEmoji($item);
                    } else {
                        $value->{$key} = $this->stripTextCustomEmoji($item);
                    }
                } elseif (is_array($item) || is_object($item)) {
                    if (is_array($value)) {
                        $value[$key] = $stripText($item);
                    } else {
                        $value->{$key} = $stripText($item);
                    }
                }
            }

            return $value;
        };
        $markup = $stripText($markup);

        return is_string($replyMarkup) ? json_encode($markup, JSON_THROW_ON_ERROR) : $markup;
    }
}
