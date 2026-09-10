<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationMediaKind;

final class TelegramWelcomeBannerSend
{
    public function __construct(
        public readonly int $messageId,
        public readonly TelegramNavigationMediaKind $mediaKind,
        public readonly ?string $reference,
    ) {}
}
