<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramLocale
{
    public function code(?Customer $customer): string { return $customer?->locale === 'en' ? 'en' : 'id'; }
    public function text(?Customer $customer, string $id, string $en): string { return $this->code($customer) === 'en' ? $en : $id; }
    public function idr(int $amount): string { return 'Rp '.number_format($amount, 0, ',', '.'); }
    public function usdt(int $micro): string { return number_format($micro / 1_000_000, 6, '.', ''); }
    public function languageKeyboard(): array { return ['inline_keyboard' => [[['text' => '🇮🇩 Bahasa Indonesia', 'callback_data' => 'locale:id'], ['text' => '🇬🇧 English', 'callback_data' => 'locale:en']]]]; }
}
