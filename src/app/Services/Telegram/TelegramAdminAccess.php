<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\ManualPaymentSetting;

class TelegramAdminAccess
{
    public function isAdmin(Customer $customer, string $chatId): bool
    {
        $adminUserId = $this->adminUserId();
        $adminChatId = $this->adminChatId();

        return ($adminUserId !== '' && (string) $customer->telegram_id === $adminUserId)
            || ($adminChatId !== '' && $chatId === $adminChatId);
    }

    public function adminChatId(): ?string
    {
        $settings = ManualPaymentSetting::current();
        $chatId = $settings?->admin_telegram_chat_id ?: config('services.telegram.admin_telegram_chat_id');
        $chatId = trim((string) $chatId);

        return $chatId === '' ? null : $chatId;
    }

    private function adminUserId(): string
    {
        return trim((string) config('services.telegram.admin_user_id'));
    }
}
