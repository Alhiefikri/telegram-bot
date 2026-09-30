<?php

namespace App\Services\Telegram;

final class TelegramProductNameRenderer
{
    public function __construct(
        private readonly CatalogIconResolver $icons,
        private readonly TelegramHtmlCaption $html,
    ) {}

    public function render(
        string $name,
        ?string $glyph,
        ?string $customEmojiId,
        string $scope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): string {
        $icon = $this->icons->resolve($glyph, $customEmojiId, $scope);

        return $this->html->icon($icon).' '.$name;
    }
}
