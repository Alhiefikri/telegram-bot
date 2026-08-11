<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;

class TelegramMenuIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private TelegramLocale $locale,
        private TelegramSupportContact $supportContact,
        private CheckoutMaintenance $maintenance,
    ) {}

    public function sendLanguagePicker(string $chatId): void
    {
        $this->bot->sendMessage($chatId, '🌐 Pilih bahasa / Choose your language', $this->locale->languageKeyboard());
    }

    public function sendWelcome(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        $firstName = trim((string) $customer->first_name) ?: $this->locale->text($customer, 'Kak', 'there');
        [$greetingIcon, $greetingText] = $this->timeAwareGreeting($customer);
        $caption = implode("\n", $this->locale->code($customer) === 'en' ? [
            $greetingIcon.' '.$greetingText.', '.$firstName.' — Welcome to LHIE STORE',
            '━━━━━━━━━━━━━━',
            'A simple, fast way to shop for digital products directly from Telegram.',
            '', '🛍️ Browse products', '💳 Pay by QRIS / transfer', '📦 Check your orders anytime', '⚡ Accounts are delivered automatically after payment is confirmed', '', 'Where would you like to start?',
        ] : [
            $greetingIcon.' '.$greetingText.', '.$firstName.' — selamat datang di LHIE STORE',
            '━━━━━━━━━━━━━━',
            'Tempat belanja produk digital yang simpel, cepat, dan langsung dari Telegram.',
            '',
            '🛍️ Lihat produk',
            '💳 Bayar via QRIS / transfer',
            '📦 Cek pesanan kapan aja',
            '⚡ Akun dikirim otomatis setelah pembayaran beres',
            '',
            'Mau mulai dari mana nih?',
        ]);

        $replyMarkup = $this->welcomeInlineKeyboard($customer, $chatId, $isPrivateChat);
        $bannerReference = config('services.telegram.welcome_banner_url');

        if (is_string($bannerReference) && trim($bannerReference) !== '') {
            $this->bot->sendPhotoReference($chatId, trim($bannerReference));
            $this->bot->sendMessage($chatId, $caption, $replyMarkup);

            return;
        }

        $bannerPath = resource_path('images/banner-welcome.jpg');
        if (is_file($bannerPath) && is_readable($bannerPath)) {
            $this->bot->sendPhoto($chatId, file_get_contents($bannerPath), 'banner-welcome.jpg');
            $this->bot->sendMessage($chatId, $caption, $replyMarkup);

            return;
        }

        $this->bot->sendMessage($chatId, $caption, $replyMarkup);
    }

    public function sendMainMenu(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        $message = $this->locale->text($customer, "☰ Menu utama\nMau ngapain dulu? Pilih tombol di bawah biar sat-set.", "☰ Main menu\nWhat would you like to do?");
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $message .= "\n\n".$this->locale->text($customer, '⏸️ Checkout sedang maintenance. Produk dan keranjang tetap bisa diakses; Order baru sementara ditahan.', '⏸️ Checkout is under maintenance. Products and the cart remain available; new Orders are temporarily paused.')
                .$this->locale->checkoutMaintenanceNote($customer, $maintenance->publicNote);
        }

        $this->bot->sendMessage($chatId, $message, $this->mainMenuKeyboard($customer, $chatId, $isPrivateChat));
    }

    public function mainMenuKeyboard(?Customer $customer = null, ?string $chatId = null, bool $isPrivateChat = true): array
    {
        $keyboard = [
            [['text' => $customer && $this->locale->code($customer) === 'en' ? '🛍️ Browse Products' : '🛍️ Lihat Produk'], ['text' => $customer && $this->locale->code($customer) === 'en' ? '📦 Orders' : '📦 Pesanan']],
            [['text' => '💬 Support'], ['text' => $customer && $this->locale->code($customer) === 'en' ? '🌐 Language' : '🌐 Bahasa']],
        ];

        if ($isPrivateChat && $customer && $chatId && $this->adminAccess->isAdmin($customer, $chatId)) {
            $adminDashboardButton = $this->adminDashboardWebAppButton('🛠️ Admin Dashboard');
            if ($adminDashboardButton !== null) {
                $keyboard[] = [$adminDashboardButton];
            }
            $keyboard[] = [['text' => '📊 Statistik'], ['text' => '📦 Cek Stok']];
            $keyboard[] = [['text' => '📣 Broadcast Info']];
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $customer && $this->locale->code($customer) === 'en' ? 'Choose a button or type /products' : 'Pilih tombol atau ketik /produk',
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function timeAwareGreeting(Customer $customer): array
    {
        $hour = now('Asia/Jakarta')->hour;

        $greeting = match (true) {
            $hour >= 5 && $hour < 11 => ['🌅', 'Selamat pagi'],
            $hour >= 11 && $hour < 15 => ['☀️', 'Selamat siang'],
            $hour >= 15 && $hour < 18 => ['🌇', 'Selamat sore'],
            default => ['🌙', 'Selamat malam'],
        };
        if ($this->locale->code($customer) !== 'en') {
            return $greeting;
        }

        return match ($greeting[1]) {
            'Selamat pagi' => [$greeting[0], 'Good morning'], 'Selamat siang' => [$greeting[0], 'Good afternoon'], 'Selamat sore' => [$greeting[0], 'Good evening'], default => [$greeting[0], 'Good evening']
        };
    }

    private function welcomeInlineKeyboard(Customer $customer, string $chatId, bool $isPrivateChat): array
    {
        $keyboard = [
            [
                ['text' => $this->locale->text($customer, '🛍️ Lihat Produk', '🛍️ Browse Products'), 'callback_data' => 'katalog'],
                ['text' => $this->locale->text($customer, '📦 Cek Pesanan', '📦 Check Orders'), 'callback_data' => 'status'],
            ],
        ];

        if ($isPrivateChat && $this->adminAccess->isAdmin($customer, $chatId)) {
            $adminDashboardButton = $this->adminDashboardWebAppButton('🌐 Mini App Dashboard');
            if ($adminDashboardButton !== null) {
                $keyboard[] = [$adminDashboardButton];
            }
        }

        return ['inline_keyboard' => $keyboard];
    }

    public function sendSupport(Customer $customer, string $chatId): void
    {
        $adminContactUrl = $this->supportContact->resolve()['url'];
        $replyMarkup = [];
        if ($adminContactUrl) {
            $replyMarkup = ['inline_keyboard' => [[['text' => $this->locale->text($customer, '💬 Hubungi Admin', '💬 Chat with Admin'), 'url' => $adminContactUrl]]]];
        }

        $this->bot->sendMessage($chatId, $this->locale->text($customer, "💬 Support\nAda kendala order, pembayaran, atau login akun?\n\nKirim detail masalah kamu di sini. Kalau soal pesanan, sertakan invoice biar lebih cepat dicek.", "💬 Support\nNeed help with an order, payment, or account login?\n\nSend the details here. Include the invoice number for order-related help."), $replyMarkup);
    }

    public function sendAccountSummary(Customer $customer, string $chatId): void
    {
        $ordersCount = $customer->orders()->count();
        $latestOrder = $customer->orders()->latest()->first();
        $lines = [
            $this->locale->text($customer, '👤 Akun Saya', '👤 My Account'),
            $this->locale->text($customer, 'Nama: ', 'Name: ').($customer->first_name ?: '-'),
            'Username: @'.($customer->username ?: '-'),
            $this->locale->text($customer, 'Total pesanan: ', 'Total orders: ').$ordersCount,
        ];
        if ($latestOrder) {
            $lines[] = $this->locale->text($customer, 'Pesanan terakhir: ', 'Latest order: ').$latestOrder->invoice_number.' · '.$this->locale->paymentStatus($customer, $latestOrder->payment_status);
        }
        $lines[] = '';
        $lines[] = $this->locale->text($customer, 'Mau cek pesanan kamu?', 'Want to check your orders?');

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => [[['text' => $this->locale->text($customer, '📦 Lihat Pesanan', '📦 View Orders'), 'callback_data' => 'status']]]]);
    }
}
