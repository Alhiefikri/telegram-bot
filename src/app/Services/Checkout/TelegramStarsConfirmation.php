<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Customer;

/**
 * Built from authenticated private Telegram update and server-side session
 * facts. Never populate this value from raw callback data.
 */
final readonly class TelegramStarsConfirmation
{
    public function __construct(
        public int $customerId,
        public int $telegramUserId,
        public int $chatId,
        string $chatType,
        public string $revision,
        public string $checkoutKey,
    ) {
        if ($customerId < 1 || $telegramUserId < 1 || $chatId !== $telegramUserId || $chatType !== 'private'
            || trim($revision) === '' || trim($checkoutKey) !== $checkoutKey || $checkoutKey === '' || strlen($checkoutKey) > 64) {
            throw new InvalidCheckout('Telegram Stars requires a private buyer and a checkout revision.');
        }
    }

    public function matchesCustomer(Customer $customer): bool
    {
        return (int) $customer->getKey() === $this->customerId && $customer->telegram_id === $this->telegramUserId;
    }
}
