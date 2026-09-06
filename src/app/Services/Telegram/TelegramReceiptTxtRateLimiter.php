<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fixed, application-owned limit for explicit TXT generation requests.
 *
 * The limit is intentionally not configurable through ENV: it protects a
 * credential-bearing action, while product/catalog configuration belongs in
 * the database. The cache key contains only internal identifiers and never
 * enters operational metadata.
 */
final class TelegramReceiptTxtRateLimiter
{
    public const MAX_ATTEMPTS = 3;

    public const DECAY_SECONDS = 600;

    public function allow(Customer $customer, Order $order): bool
    {
        $key = 'telegram:receipt-txt-download:'.$customer->getKey().':'.$order->getKey();

        return (bool) RateLimiter::attempt(
            $key,
            self::MAX_ATTEMPTS,
            static fn (): bool => true,
            self::DECAY_SECONDS,
        );
    }
}
