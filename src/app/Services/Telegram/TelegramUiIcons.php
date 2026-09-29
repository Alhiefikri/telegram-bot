<?php

namespace App\Services\Telegram;

use App\Models\TelegramUiIcon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves database-backed Telegram presentation icon slots. Product-owned
 * icons use CatalogIconResolver and never pass through this shared registry.
 */
class TelegramUiIcons
{
    public const CACHE_KEY = 'telegram-ui-icons-v2';

    private const CACHE_MINUTES = 10;

    private bool $memoLoaded = false;

    /** @var list<array<string, mixed>> */
    private array $memoAttributes = [];

    /** @var Collection<int, TelegramUiIcon> */
    private Collection $memoIcons;

    /** @var array<string, TelegramUiIcon> */
    private array $memoIconsByKey = [];

    /** @var Collection<int, TelegramUiIcon> */
    private Collection $memoMessageIcons;

    /** @var array{pattern: string, icons: Collection<string, TelegramUiIcon>}|null */
    private ?array $memoLegacyRender = null;

    public function __construct(private CustomEmojiCapability $customEmoji)
    {
        $this->memoIcons = new Collection;
        $this->memoMessageIcons = new Collection;
    }

    /**
     * @return Collection<int, TelegramUiIcon>
     */
    public function all(): Collection
    {
        $this->hydrate();

        return $this->memoIcons;
    }

    /**
     * Rendering a single message resolves the same registry dozens of times, so
     * hydration and derived lookups are reused until the cached payload itself
     * changes. The cache stays the source of truth: an edit made by any process
     * replaces the payload, which invalidates this in-memory state.
     */
    private function hydrate(): void
    {
        $attributes = $this->payloadAttributes();

        if ($this->memoLoaded && $attributes === $this->memoAttributes) {
            return;
        }

        $icons = TelegramUiIcon::query()->hydrate($attributes);

        $this->memoLoaded = true;
        $this->memoAttributes = $attributes;
        $this->memoIcons = $icons;
        $this->memoIconsByKey = $icons->keyBy('key')->all();
        $this->memoMessageIcons = $icons
            ->filter(static fn (TelegramUiIcon $icon): bool => str_starts_with((string) $icon->key, 'message.'))
            ->values();
        $this->memoLegacyRender = null;
    }

    /**
     * Cache raw attributes, not Eloquent objects: cache unserialization rejects
     * classes, and a payload written by an older release must never break a
     * read — anything that is not the expected array is rebuilt from the table.
     *
     * @return list<array<string, mixed>>
     */
    private function payloadAttributes(): array
    {
        $attributes = Cache::get(self::CACHE_KEY);
        if (is_array($attributes)) {
            return $attributes;
        }

        $attributes = TelegramUiIcon::query()
            ->catalogOrder()
            ->get()
            ->map(static fn (TelegramUiIcon $icon): array => $icon->getAttributes())
            ->values()
            ->all();

        Cache::put(self::CACHE_KEY, $attributes, now()->addMinutes(self::CACHE_MINUTES));

        return $attributes;
    }

    public function customEmojiId(string $key): ?string
    {
        $icon = $this->icon($key);

        return $icon instanceof TelegramUiIcon ? $this->customEmojiIdForIcon($icon) : null;
    }

    /**
     * @return Collection<int, TelegramUiIcon>
     */
    public function messageCopyIcons(): Collection
    {
        $this->hydrate();

        return $this->memoMessageIcons;
    }

    public function renderTextIcon(string $key): string
    {
        $icon = $this->icon($key);

        return $icon instanceof TelegramUiIcon ? $this->renderIcon($icon) : '';
    }

    /**
     * Resolve only static tokens in trusted copy. Call before inserting dynamic
     * customer, Product, credential, or broadcast values.
     */
    public function renderLegacyIcons(string $text): string
    {
        $render = $this->legacyRender();
        if ($render === null) {
            return $text;
        }

        return preg_replace_callback($render['pattern'], function (array $match) use ($render): string {
            $icon = $render['icons']->get($match[0]);

            return $icon instanceof TelegramUiIcon ? $this->renderIcon($icon) : $match[0];
        }, $text) ?? $text;
    }

    /**
     * @return array{pattern: string, icons: Collection<string, TelegramUiIcon>}|null
     */
    private function legacyRender(): ?array
    {
        $this->hydrate();

        if ($this->memoLegacyRender !== null) {
            return $this->memoLegacyRender;
        }

        // Menu button slots are matched here too: an emoji that owns a menu
        // slot must follow that icon wherever it appears in copy or a button
        // label, so one emoji stays editable in exactly one list.
        $icons = $this->memoIcons
            ->filter(static fn (TelegramUiIcon $icon): bool => trim((string) $icon->source_emoji) !== '')
            ->keyBy('source_emoji');
        $sourceEmojis = $icons->keys()->map(static fn (mixed $emoji): string => (string) $emoji)->all();
        if ($sourceEmojis === []) {
            return null;
        }

        usort($sourceEmojis, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $this->memoLegacyRender = [
            'pattern' => '~'.implode('|', array_map(static fn (string $emoji): string => preg_quote($emoji, '~'), $sourceEmojis)).'~u',
            'icons' => $icons,
        ];
    }

    /**
     * Build the text and optional icon field of one navigation button, ready to
     * spread into a button definition.
     *
     * @return array{text: string}|array{text: string, icon_custom_emoji_id: string}
     */
    public function apply(string $key, string $label): array
    {
        $icon = $this->icon($key);
        if (! $icon instanceof TelegramUiIcon) {
            return ['text' => $label];
        }

        $text = $this->withoutLeadingGlyph($label);
        $id = $this->customEmojiIdForIcon($icon);
        if ($id === null) {
            return ['text' => trim($icon->fallback_emoji.' '.$text)];
        }

        return [
            'text' => $text,
            'icon_custom_emoji_id' => $id,
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function icon(string $key): ?TelegramUiIcon
    {
        $this->hydrate();

        return $this->memoIconsByKey[$key] ?? null;
    }

    private function customEmojiIdForIcon(TelegramUiIcon $icon): ?string
    {
        if (! $this->customEmoji->isAvailable()) {
            return null;
        }

        $id = trim((string) $icon->custom_emoji_id);

        return $id !== '' ? $id : null;
    }

    private function renderIcon(TelegramUiIcon $icon): string
    {
        $glyph = trim((string) $icon->fallback_emoji);
        $id = $this->customEmojiIdForIcon($icon);
        if ($id === null) {
            return $glyph;
        }

        return sprintf(
            '<tg-emoji emoji-id="%s" data-omp-icon="1">%s</tg-emoji>',
            htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($glyph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function withoutLeadingGlyph(string $label): string
    {
        $label = preg_replace('~^<tg-emoji\\b[^>]*>[^<]*</tg-emoji>\\s*~u', '', $label) ?? $label;
        $stripped = preg_replace('/^[^\\p{L}\\p{N}]+\\s*/u', '', $label);

        return is_string($stripped) && trim($stripped) !== '' ? $stripped : $label;
    }
}
