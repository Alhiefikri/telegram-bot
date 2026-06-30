<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramMenuIntents
{
    public function __construct(private TelegramBotClient $bot, private TelegramAdminAccess $adminAccess) {}

    public function sendWelcome(Customer $customer, string $chatId): void
    {
        $firstName = trim((string) $customer->first_name) ?: 'kak';
        $caption = implode("\n", [
            '🌙 Selamat datang, '.$firstName.' 👋',
            '━━━━━━━━━━━━━━',
            '🛒 Belanja produk digital langsung dari Telegram.',
            '💳 Pembayaran otomatis via website/QRIS.',
            '📦 Cek status dan riwayat pesanan kapan saja.',
            '🌐 Buka Mini App untuk dashboard mobile.',
            '',
            'Pilih menu di bawah untuk mulai.',
        ]);

        $replyMarkup = $this->welcomeInlineKeyboard($customer, $chatId);
        $bannerUrl = config('services.telegram.welcome_banner_url');

        if (is_string($bannerUrl) && $bannerUrl !== '') {
            $this->bot->sendPhotoReference($chatId, $bannerUrl, $caption, $replyMarkup);

            return;
        }

        $this->bot->sendMessage($chatId, $caption, $replyMarkup);
    }

    public function sendMainMenu(Customer $customer, string $chatId): void
    {
        $this->bot->sendMessage($chatId, "☰ Menu utama\nPilih tombol di bawah supaya lebih cepat belanja.", $this->mainMenuKeyboard($customer, $chatId));
    }

    public function mainMenuKeyboard(?Customer $customer = null, ?string $chatId = null): array
    {
        $keyboard = [
            [['text' => '🛍️ Marketplace'], ['text' => '📦 Pesanan']],
            [['text' => '👤 Akun Saya'], ['text' => '💬 Support']],
        ];

        if ($customer && $chatId && $this->adminAccess->isAdmin($customer, $chatId)) {
            $keyboard[] = [[
                'text' => '🛠️ Admin Dashboard',
                'web_app' => ['url' => route('admin.dashboard').'?v='.time()],
            ]];
            $keyboard[] = [['text' => '📣 Broadcast Info']];
        }

        $keyboard[] = [['text' => '☰ Menu']];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'Pilih menu atau ketik /cari produk',
        ];
    }

    private function welcomeInlineKeyboard(Customer $customer, string $chatId): array
    {
        $keyboard = [
            [
                ['text' => '🛍️ Lihat Katalog', 'callback_data' => 'katalog'],
                ['text' => '📦 Pesanan', 'callback_data' => 'status'],
            ],
        ];

        if ($this->adminAccess->isAdmin($customer, $chatId)) {
            $keyboard[] = [[
                'text' => '🌐 Mini App Dashboard',
                'web_app' => ['url' => route('admin.dashboard').'?v='.time()],
            ]];
        }

        return ['inline_keyboard' => $keyboard];
    }

    public function sendSupport(string $chatId): void
    {
        $adminContactUrl = config('services.telegram.admin_contact_url');
        $adminUsername = config('services.telegram.admin_username');
        $replyMarkup = [];
        if ($adminContactUrl) {
            $replyMarkup = ['inline_keyboard' => [[['text' => '💬 Chat Admin', 'url' => $adminContactUrl]]]];
        } elseif ($adminUsername) {
            $replyMarkup = ['inline_keyboard' => [[['text' => '💬 Chat Admin', 'url' => 'https://t.me/'.ltrim((string) $adminUsername, '@')]]]];
        }

        $this->bot->sendMessage($chatId, "💬 Support\nButuh bantuan order atau pembayaran? Hubungi admin, atau kirim detail invoice kamu di sini.", $replyMarkup);
    }

    public function sendAccountSummary(Customer $customer, string $chatId): void
    {
        $ordersCount = $customer->orders()->count();
        $latestOrder = $customer->orders()->latest()->first();
        $lines = [
            '👤 Akun Saya',
            'Nama: '.($customer->first_name ?: '-'),
            'Username: @'.($customer->username ?: '-'),
            'Total pesanan: '.$ordersCount,
        ];
        if ($latestOrder) {
            $lines[] = 'Pesanan terakhir: '.$latestOrder->invoice_number.' · '.$latestOrder->payment_status->value;
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => [[['text' => '📦 Lihat Pesanan', 'callback_data' => 'status']]]]);
    }
}
