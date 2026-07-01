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
            '🌙 Halo '.$firstName.', welcome ke LHIE STORE 👋',
            '━━━━━━━━━━━━━━',
            'Tempat belanja produk digital yang simpel, cepat, dan langsung dari Telegram.',
            '',
            '🛍️ Pilih produk',
            '💳 Bayar via QRIS / transfer',
            '📦 Cek pesanan kapan aja',
            '⚡ Akun dikirim otomatis setelah pembayaran beres',
            '',
            'Mau mulai dari mana nih?',
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
        $this->bot->sendMessage($chatId, "☰ Menu utama\nMau ngapain dulu? Pilih tombol di bawah biar sat-set 🚀", $this->mainMenuKeyboard($customer, $chatId));
    }

    public function mainMenuKeyboard(?Customer $customer = null, ?string $chatId = null): array
    {
        $keyboard = [
            [['text' => '🛍️ Marketplace'], ['text' => '📦 Pesanan']],
            [['text' => '👤 Akun Saya'], ['text' => '💬 Support']],
        ];

        if ($customer && $chatId && $this->adminAccess->isAdmin($customer, $chatId)) {
            $adminDashboardButton = $this->adminDashboardWebAppButton('🛠️ Admin Dashboard');
            if ($adminDashboardButton !== null) {
                $keyboard[] = [$adminDashboardButton];
            }
            $keyboard[] = [['text' => '📣 Broadcast Info']];
        }

        $keyboard[] = [['text' => '☰ Menu']];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'Pilih menu atau ketik /cari nama produk',
        ];
    }

    private function welcomeInlineKeyboard(Customer $customer, string $chatId): array
    {
        $keyboard = [
            [
                ['text' => '🛍️ Buka Katalog', 'callback_data' => 'katalog'],
                ['text' => '📦 Cek Pesanan', 'callback_data' => 'status'],
            ],
        ];

        if ($this->adminAccess->isAdmin($customer, $chatId)) {
            $adminDashboardButton = $this->adminDashboardWebAppButton('🌐 Mini App Dashboard');
            if ($adminDashboardButton !== null) {
                $keyboard[] = [$adminDashboardButton];
            }
        }

        return ['inline_keyboard' => $keyboard];
    }

    private function adminDashboardWebAppButton(string $text): ?array
    {
        $url = $this->adminDashboardWebAppUrl();

        if (! $this->isTelegramWebAppUrl($url)) {
            return null;
        }

        return [
            'text' => $text,
            'web_app' => ['url' => $url],
        ];
    }

    private function adminDashboardWebAppUrl(): string
    {
        $configuredUrl = config('services.telegram.mini_app_url');
        $url = is_string($configuredUrl) && trim($configuredUrl) !== ''
            ? trim($configuredUrl)
            : route('admin.dashboard');

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.time();
    }

    private function isTelegramWebAppUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
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

        $this->bot->sendMessage($chatId, "💬 Support\n━━━━━━━━━━━━━━\nAda kendala order, pembayaran, atau login akun?\n\nKirim detail masalah kamu di sini. Kalau soal pesanan, sertakan invoice biar lebih cepat dicek.", $replyMarkup);
    }

    public function sendAccountSummary(Customer $customer, string $chatId): void
    {
        $ordersCount = $customer->orders()->count();
        $latestOrder = $customer->orders()->latest()->first();
        $lines = [
            '👤 Akun Saya',
            '━━━━━━━━━━━━━━',
            'Nama: '.($customer->first_name ?: '-'),
            'Username: @'.($customer->username ?: '-'),
            'Total pesanan: '.$ordersCount,
        ];
        if ($latestOrder) {
            $lines[] = 'Pesanan terakhir: '.$latestOrder->invoice_number.' · '.$latestOrder->payment_status->value;
        }
        $lines[] = '';
        $lines[] = 'Mau cek pesanan kamu?';

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => [[['text' => '📦 Lihat Pesanan', 'callback_data' => 'status']]]]);
    }
}
