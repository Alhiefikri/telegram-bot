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

    public function sendLanguagePicker(string $chatId, ?Customer $customer = null): void
    {
        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'language.picker.prompt'), $this->locale->languageKeyboard());
    }

    public function sendWelcome(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        $firstName = trim((string) $customer->first_name);
        if ($firstName === '') {
            $firstName = $this->locale->translate($customer, 'welcome.default_name');
        }

        [$greetingIcon, $greetingText] = $this->timeAwareGreeting($customer);
        $caption = $this->locale->translate($customer, 'welcome.caption', [
            'greeting' => $greetingIcon.' '.$greetingText,
            'first_name' => $firstName,
        ]);
        $replyMarkup = $this->welcomeInlineKeyboard($customer, $chatId, $isPrivateChat);
        $bannerReference = config('services.telegram.welcome_banner_url');

        if (is_string($bannerReference) && trim($bannerReference) !== '') {
            $this->bot->sendPhotoReference($chatId, trim($bannerReference), $caption, $replyMarkup);

            return;
        }

        $bannerPath = resource_path('images/banner-welcome.jpg');
        if (is_file($bannerPath) && is_readable($bannerPath)) {
            $contents = file_get_contents($bannerPath);
            if ($contents !== false) {
                $this->bot->sendPhoto($chatId, $contents, 'banner-welcome.jpg', $caption, $replyMarkup);

                return;
            }
        }

        $this->bot->sendMessage($chatId, $caption, $replyMarkup);
    }

    public function sendMainMenu(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        $message = $this->locale->translate($customer, 'menu.title');
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $message .= "\n\n".$this->locale->translate($customer, 'menu.maintenance');
            if ($maintenance->publicNote !== null) {
                $message .= $this->locale->translate($customer, 'maintenance.admin_note', ['note' => $maintenance->publicNote]);
            }
        }

        $this->bot->sendMessage($chatId, $message, $this->mainMenuKeyboard($customer, $chatId, $isPrivateChat));
    }

    public function mainMenuKeyboard(?Customer $customer = null, ?string $chatId = null, bool $isPrivateChat = true): array
    {
        $keyboard = [
            [
                ['text' => $this->locale->translate($customer, 'menu.products')],
                ['text' => $this->locale->translate($customer, 'menu.orders')],
            ],
            [
                ['text' => $this->locale->translate($customer, 'menu.support')],
                ['text' => $this->locale->translate($customer, 'menu.language')],
            ],
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
            'input_field_placeholder' => $this->locale->translate($customer, 'menu.placeholder'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function timeAwareGreeting(Customer $customer): array
    {
        $hour = now('Asia/Jakarta')->hour;

        return match (true) {
            $hour >= 5 && $hour < 11 => ['🌅', $this->locale->translate($customer, 'welcome.greeting.morning')],
            $hour >= 11 && $hour < 15 => ['☀️', $this->locale->translate($customer, 'welcome.greeting.afternoon')],
            $hour >= 15 && $hour < 18 => ['🌇', $this->locale->translate($customer, 'welcome.greeting.evening')],
            default => ['🌙', $this->locale->translate($customer, 'welcome.greeting.night')],
        };
    }

    private function welcomeInlineKeyboard(Customer $customer, string $chatId, bool $isPrivateChat): array
    {
        $keyboard = [
            [
                ['text' => $this->locale->translate($customer, 'welcome.action.products'), 'callback_data' => 'katalog'],
                ['text' => $this->locale->translate($customer, 'welcome.action.orders'), 'callback_data' => 'status'],
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
            $replyMarkup = ['inline_keyboard' => [[['text' => $this->locale->translate($customer, 'support.contact'), 'url' => $adminContactUrl]]]];
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'support.message'), $replyMarkup);
    }

    public function sendAccountSummary(Customer $customer, string $chatId): void
    {
        $ordersCount = $customer->orders()->count();
        $latestOrder = $customer->orders()->latest()->first();
        $lines = [
            $this->locale->translate($customer, 'account.title'),
            $this->locale->translate($customer, 'account.name').($customer->first_name ?: '-'),
            $this->locale->translate($customer, 'account.username').'@'.($customer->username ?: '-'),
            $this->locale->translate($customer, 'account.total_orders').$ordersCount,
        ];
        if ($latestOrder) {
            $lines[] = $this->locale->translate($customer, 'account.latest_order').$latestOrder->invoice_number.' · '.$this->locale->accountPaymentStatus($customer, $latestOrder->payment_status);
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'account.follow_up');

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => [[['text' => $this->locale->translate($customer, 'account.orders_button'), 'callback_data' => 'status']]]]);
    }
}
