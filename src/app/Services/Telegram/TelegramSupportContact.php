<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;

class TelegramSupportContact
{
    /**
     * @return array{username: string, url: string}
     */
    public function resolve(): array
    {
        $settings = ManualPaymentSetting::current();
        $configured = $this->contact(
            $settings?->admin_contact_url,
            $settings?->admin_username,
        );
        if ($configured['url'] !== '') {
            return $configured;
        }

        return $this->contact(
            config('services.telegram.admin_contact_url') ?: config('services.support.telegram_admin_url'),
            config('services.telegram.admin_username') ?: config('services.support.telegram_admin_username'),
        );
    }

    /**
     * @return array{username: string, url: string}
     */
    private function contact(mixed $url, mixed $username): array
    {
        $url = trim((string) $url);
        $username = ltrim(trim((string) $username), '@');

        return [
            'username' => $username,
            'url' => $url !== '' ? $url : ($username !== '' ? 'https://t.me/'.$username : ''),
        ];
    }
}
