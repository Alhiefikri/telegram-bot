<?php

namespace App\Services\TelegramStars;

use App\Services\Telegram\TelegramBotApiRequestFactory;

final class TelegramStarsAdmission
{
    public static function permitsNewPayments(): bool
    {
        // Public admission requires a separate cutover change. The Owner's
        // issuance switch is an operational pause, not release authorization.
        return app()->environment('testing') || TelegramBotApiRequestFactory::usesTestNetwork();
    }
}
