<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationRenderOutcome;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\StoreBalance\StoreBalanceLedger;
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
        private TelegramStoreStatsReport $statsReport,
        private TelegramNavigationRootService $roots,
        private TelegramWelcomeBanner $banner,
        private TelegramHtmlCaption $captions,
        private StoreBalanceLedger $balances,
    ) {}

    public function sendLanguagePicker(string $chatId, ?Customer $customer = null, bool $isPrivateChat = true): void
    {
        $caption = $this->captions->html($this->locale->translate($customer, 'language.picker.prompt'));

        if ($customer === null || ! $isPrivateChat) {
            $this->bot->sendMessage($chatId, $caption, $this->locale->languageKeyboard(), ['parse_mode' => 'HTML']);

            return;
        }

        $this->presentRoot($customer, $chatId, new TelegramNavigationSurface(
            TelegramNavigationScreen::Picker,
            $customer->locale === null ? null : $this->locale->code($customer),
            $caption,
            $this->locale->languageKeyboard(),
        ));
    }

    public function sendWelcome(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        if (! $isPrivateChat) {
            $this->banner->send(
                $chatId,
                $this->captions->html($this->groupCaption($customer)),
                $this->mainMenuKeyboard($customer, $chatId, false),
            );

            return;
        }

        $this->roots->establish($customer, $chatId, $this->homeSurface($customer, $chatId));
    }

    public function renderHome(TelegramNavigationRoot $root, Customer $customer, string $chatId): TelegramNavigationRenderOutcome
    {
        return $this->roots->render($root, $chatId, $this->homeSurface($customer, $chatId));
    }

    private function presentRoot(Customer $customer, string $chatId, TelegramNavigationSurface $surface): void
    {
        $root = $this->roots->current($customer);
        if ($root === null || $root->chat_id !== $chatId) {
            $this->roots->establish($customer, $chatId, $surface);

            return;
        }

        $this->roots->render($root, $chatId, $surface);
    }

    private function homeSurface(Customer $customer, string $chatId): TelegramNavigationSurface
    {
        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Home,
            $this->locale->code($customer),
            $this->captions->html($this->privateCaption($customer)),
            $this->homeKeyboard($customer, $chatId),
        );
    }

    private function homeKeyboard(Customer $customer, string $chatId): array
    {
        $keyboard = ['inline_keyboard' => [
            [
                ['text' => $this->locale->translate($customer, 'menu.products'), 'callback_data' => 'katalog'],
            ],
            [
                ['text' => $this->locale->translate($customer, 'menu.balance'), 'callback_data' => 'balance'],
                ['text' => $this->locale->translate($customer, 'menu.orders'), 'callback_data' => 'menu:orders'],
            ],
            [
                ['text' => $this->locale->translate($customer, 'menu.language'), 'callback_data' => 'menu:language'],
                ['text' => $this->locale->translate($customer, 'menu.support'), 'callback_data' => 'menu:support'],
            ],
        ]];

        if ($this->adminAccess->isAdmin($customer, $chatId)) {
            $adminButton = $this->adminDashboardInlineButton('🛠️ Admin Dashboard');
            if ($adminButton !== null) {
                $keyboard['inline_keyboard'][] = [$adminButton];
            }
        }

        return $keyboard;
    }

    private function privateCaption(Customer $customer): string
    {
        return $this->locale->translate($customer, 'welcome.caption', $this->welcomeReplacements($customer))
            .$this->maintenanceNotice($customer);
    }

    private function groupCaption(Customer $customer): string
    {
        $metrics = $this->statsReport->startMetrics();

        return $this->locale->translate($customer, 'welcome.group_caption', [
            'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ]).$this->maintenanceNotice($customer);
    }

    /**
     * The Customer Store Balance is read fresh from its own projection and is
     * never part of the shared bounded-staleness global metrics cache.
     *
     * @return array<string, string|int>
     */
    private function welcomeReplacements(Customer $customer): array
    {
        $metrics = $this->statsReport->startMetrics();
        $balance = $this->balances->snapshot($customer);

        return [
            'greeting' => implode(' ', $this->timeAwareGreeting($customer)),
            'first_name' => $this->welcomeName($customer),
            'telegram_id' => (string) $customer->telegram_id,
            'username' => $this->displayUsername($customer),
            'balance' => $balance->isAvailable()
                ? $this->locale->idr($balance->balance())
                : $this->locale->translate($customer, 'welcome.balance_unavailable'),
            'bot_users' => $metrics['bot_users'],
            'successful_orders' => $metrics['successful_orders'],
        ];
    }

    public function sendMainMenu(Customer $customer, string $chatId, bool $isPrivateChat = true): void
    {
        $message = $this->locale->translate($customer, 'menu.title');
        $message .= $this->maintenanceNotice($customer);

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
            } elseif ($this->adminDashboardInlineButton('🛠️ Admin Dashboard') !== null) {
                $keyboard[] = [['text' => '🛠️ Admin Dashboard']];
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

    private function maintenanceNotice(Customer $customer): string
    {
        $maintenance = $this->maintenance->current();
        if (! $maintenance->active) {
            return '';
        }

        $message = "\n\n".$this->locale->translate($customer, 'menu.maintenance');
        if ($maintenance->publicNote !== null) {
            $message .= $this->locale->translate($customer, 'maintenance.admin_note', ['note' => $maintenance->publicNote]);
        }

        return $message;
    }

    private function welcomeName(Customer $customer): string
    {
        $firstName = $this->profileName($customer);

        return $firstName !== '' ? $firstName : $this->locale->translate($customer, 'welcome.default_name');
    }

    private function profileName(Customer $customer): string
    {
        $firstName = trim((string) $customer->first_name);

        return strcasecmp($firstName, 'Telegram Customer') === 0 ? '' : $firstName;
    }

    private function displayUsername(Customer $customer): string
    {
        $username = trim((string) $customer->username);

        return $username !== '' ? '@'.ltrim($username, '@') : $this->locale->translate($customer, 'welcome.missing_value');
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
