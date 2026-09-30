<?php

namespace App\Services\Telegram;

final class TelegramProductNameRenderer
{
    public function __construct(
        private readonly CatalogIconResolver $icons,
        private readonly TelegramHtmlCaption $html,
    ) {}

    public function render(string $name, ?string $glyph, ?string $customEmojiId): string
    {
        $icon = $this->icons->resolve($glyph, $customEmojiId);

        return $this->html->icon($icon).' '.$name;
    }
}
