<?php

namespace App\Services\TelegramStars;

use App\Services\MoneyReceipts\MoneyReceiptIdentity;

final class TelegramStarsChargeIdentity
{
    public static function configuredNamespace(): ?string
    {
        // The BotFather token's numeric prefix is the bot ID. Rotating the
        // secret suffix or moving the Bot API proxy must not change ownership.
        $token = config('services.telegram.token');
        if (! is_string($token) || preg_match('/\A([1-9][0-9]*):[A-Za-z0-9_-]+\z/', $token, $parts) !== 1) {
            return null;
        }

        return hash('sha256', app()->environment()."\0".$parts[1]);
    }

    public static function hash(string $charge): string
    {
        return hash('sha256', $charge);
    }

    public static function incoming(string $namespace, string $chargeHash): MoneyReceiptIdentity
    {
        // Charge IDs are opaque and potentially long. Do not trim, lowercase,
        // or copy the raw identifier into the generic registry.
        return MoneyReceiptIdentity::providerReference('telegram_stars', $namespace, $chargeHash);
    }
}
