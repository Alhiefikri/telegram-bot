<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationScreen;

final class TelegramNavigationSurface
{
    /**
     * @param  array<string, mixed>  $keyboard
     */
    public function __construct(
        public readonly TelegramNavigationScreen $screen,
        public readonly ?string $locale,
        public readonly string $caption,
        public readonly array $keyboard,
    ) {}
}
