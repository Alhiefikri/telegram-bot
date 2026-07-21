<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramCallbackContext
{
    public function __construct(
        public Customer $customer,
        public string $chatId,
        public string $callbackQueryId,
        public string $data,
        public string $chatType = 'private',
        public ?int $messageId = null,
    ) {}

    public function isPrivate(): bool
    {
        return $this->chatType === 'private';
    }
}
