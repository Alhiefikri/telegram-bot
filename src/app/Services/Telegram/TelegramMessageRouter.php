<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Models\Customer;
use App\Services\Telegram\Callbacks\TelegramBalanceFundingCallbackHandler;
use Illuminate\Support\Arr;

class TelegramMessageRouter
{
    private const SUPPORT_COMMANDS = ['/support', '/bantuan', '💬 Support', '💬 Sokongan'];

    private const ACCOUNT_COMMANDS = ['/akun', '/akaun', '/account', '👤 Akun Saya', '👤 My Account', '👤 Akaun Saya'];

    private const ORDER_STATUS_COMMANDS = ['/status', '/pesanan', '/orders', '📦 Pesanan', '📦 Orders'];

    private const ORDER_HISTORY_COMMANDS = ['/riwayat', '/sejarah', '/history'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramIntentSession $session,
        private TelegramStartIntentSession $startIntents,
        private TelegramCatalogQuerySession $catalogQueries,
        private TelegramMenuIntents $menu,
        private TelegramCatalogIntents $catalog,
        private TelegramOrderIntents $orders,
        private TelegramManualPaymentIntents $manualPayments,
        private TelegramManualTopUpIntents $manualTopUps,
        private TelegramAdminAccess $adminAccess,
        private TelegramAdminStatsIntents $adminStats,
        private TelegramBroadcastIntents $broadcasts,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramLocale $locale,
        private TelegramNavigationPresenter $navigation,
        private TelegramBinanceVerifyIntents $binanceVerify,
        private TelegramBscVerifyIntents $bscVerify,
        private TelegramBalanceFundingCallbackHandler $balanceFunding,
    ) {}

    public function route(Customer $customer, string $chatId, array $message, string $chatType = 'private'): void
    {
        $text = TelegramBroadcastCommand::normalize((string) (Arr::get($message, 'text') ?: Arr::get($message, 'caption', '')));
        $isPrivateChat = $this->chatPrivacy->isPrivate($chatType);
        if (! $isPrivateChat && $this->isPrivateOnlyCommand($text)) {
            $this->session->forgetWithLock($customer);
            $this->chatPrivacy->denyMessage($customer, $chatId);

            return;
        }
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->session->forgetWithLock($customer);
            $this->catalogQueries->forget($customer);
            $this->startIntents->forget($customer);
            $startIntent = $this->startIntent($text);
            if ($startIntent !== null) {
                $this->startIntents->put($customer, $startIntent);
            }
            if (! $isPrivateChat) {
                if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
                    return;
                }
                $this->menu->sendWelcome($customer, $chatId, false);

                return;
            }
            if ($customer->locale === null) {
                $this->menu->sendLanguagePicker($chatId, $customer);

                return;
            }
            if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
                return;
            }
            $pendingIntent = $this->startIntents->pull($customer);
            if ($pendingIntent !== null && $this->catalog->openStartIntent($customer, $chatId, $pendingIntent)) {
                return;
            }
            $this->menu->sendWelcome($customer, $chatId, $isPrivateChat);

            return;
        }

        if (in_array($text, ['/language', '/bahasa', '🌐 Language', '🌐 Bahasa'], true)) {
            $this->catalogQueries->forget($customer);
            $this->menu->sendLanguagePicker($chatId, $customer, $isPrivateChat);

            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forgetWithLock($customer);

            return;
        }

        if ($this->balanceFunding->isDepositCommand($text)) {
            $this->session->forgetWithLock($customer);
            $this->catalogQueries->forget($customer);
            $this->balanceFunding->handleDepositCommand($customer, $chatId, $text);

            return;
        }

        if ($text === '🛠️ Admin Dashboard') {
            $this->session->forgetWithLock($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'router.access_denied'));

                return;
            }

            $this->adminStats->sendDashboardLink($chatId);

            return;
        }

        if (in_array($text, ['📊 Statistik', '📊 Statistik Harian'], true)) {
            $this->session->forgetWithLock($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'router.access_denied'));

                return;
            }

            $this->adminStats->showStoreGrowth($customer, $chatId);

            return;
        }

        if ($text === '📦 Cek Stok' || $text === '/stok') {
            $this->session->forgetWithLock($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'router.access_denied'));

                return;
            }

            $this->adminStats->showProductStock($chatId);

            return;
        }
        if (in_array($text, ['/produk', '/product', '/products', '/lihat_produk', '/lihat produk', '/katalog', '🛍️ Lihat Produk', '🛍️ Browse Products'], true)) {
            $this->catalogQueries->forget($customer);
            $this->session->forgetWithLock($customer);
            $this->catalog->sendCategoryCatalog($customer, $chatId);

            return;
        }
        if (in_array($text, ['/menu'], true)) {
            $this->catalogQueries->forget($customer);
            $this->session->forgetWithLock($customer);

            if (! $isPrivateChat) {
                $this->menu->sendWelcome($customer, $chatId, false);

                return;
            }

            if ($customer->locale === null) {
                $this->menu->sendLanguagePicker($chatId, $customer);

                return;
            }

            $this->menu->presentHome($customer, $chatId);

            return;
        }

        if (in_array($text, self::SUPPORT_COMMANDS, true)) {
            $this->session->forgetWithLock($customer);
            $this->navigation->presentOrSend($customer, $chatId, $this->menu->supportSurface($customer), $isPrivateChat);

            return;
        }

        if ($text === '📣 Broadcast Info') {
            $this->session->forgetWithLock($customer);
            $this->broadcasts->handleMenu($customer, $chatId);

            return;
        }

        if ($this->isBroadcastCommand($text)) {
            $this->session->forgetWithLock($customer);
            $this->broadcasts->handleCommand($customer, $chatId, $text, $message);

            return;
        }

        if (in_array($text, self::ACCOUNT_COMMANDS, true)) {
            $this->session->forgetWithLock($customer);
            $this->menu->sendAccountSummary($customer, $chatId);

            return;
        }

        if ($text === '/cari' || str_starts_with($text, '/cari ')
            || $text === '/carian' || str_starts_with($text, '/carian ')
            || $text === '/search' || str_starts_with($text, '/search ')) {
            $this->session->forgetWithLock($customer);
            $command = str_starts_with($text, '/search')
                ? '/search'
                : (str_starts_with($text, '/carian') ? '/carian' : '/cari');
            $keyword = trim(substr($text, strlen($command)));
            if ($keyword === '') {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'search.prompt'));

                return;
            }

            $this->catalog->sendSearchResults($customer, $chatId, $keyword);

            return;
        }

        if ($text === '/cancel' || str_starts_with($text, '/cancel ')) {
            $this->session->forgetWithLock($customer);
            $this->manualPayments->cancelPendingOrderFromTelegram($customer, $chatId, trim(substr($text, strlen('/cancel'))));

            return;
        }

        if ($text === '/batal') {
            $this->session->forgetWithLock($customer);
            $this->binanceVerify->cancelPrompt($customer);
            $this->bscVerify->cancelPrompt($customer);
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.cancelled'));

            return;
        }

        if ($text === '/payment' || str_starts_with($text, '/payment ')) {
            $this->session->forgetWithLock($customer);
            $invoice = trim(substr($text, strlen('/payment')));
            if ($invoice === '' && $this->manualTopUps->submitTopUpProof($customer, $chatId, $message)) {
                return;
            }

            $this->manualPayments->submitManualPaymentProof($customer, $chatId, $message, $invoice);

            return;
        }

        if ($isPrivateChat && $text === '' && $this->manualPayments->rememberPendingProofPhoto($customer, $message)) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.photo_received'));

            return;
        }

        if (in_array($text, self::ORDER_STATUS_COMMANDS, true)) {
            $this->session->forgetWithLock($customer);
            $this->orders->sendLatestOrderStatus($customer, $chatId);

            return;
        }

        if (in_array($text, self::ORDER_HISTORY_COMMANDS, true)) {
            $this->session->forgetWithLock($customer);
            $this->navigation->presentOrSend($customer, $chatId, $this->orders->orderHistorySurface($customer), $isPrivateChat);

            return;
        }

        if ($text === '/order' || str_starts_with($text, '/order ')) {
            $this->session->forgetWithLock($customer);
            $invoice = trim(substr($text, strlen('/order')));
            if ($invoice === '') {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'order.detail.invoice_prompt', [
                    'example' => 'INV-12345678',
                ]));

                return;
            }
            $this->orders->sendOrderDetail($customer, $chatId, $invoice);

            return;
        }

        if ($isPrivateChat && $this->bscVerify->handleHashInput($customer, $chatId, $text)) {
            return;
        }

        if ($isPrivateChat && $this->binanceVerify->handleOrderIdInput($customer, $chatId, $text)) {
            return;
        }

        $selection = $this->session->get($customer);
        if ($selection !== null && ! $isPrivateChat) {
            $this->session->forgetWithLock($customer);
            $this->chatPrivacy->denyMessage($customer, $chatId);

            return;
        }

        if ($selection !== null && $selection['mode'] === 'awaiting_custom_quantity') {
            $this->catalog->handleCustomQuantityInput($customer, $chatId, $text);

            return;
        }

        if ($selection !== null) {
            $this->session->forgetWithLock($customer);
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'router.unknown_command'));

    }

    private function isPrivateOnlyCommand(string $text): bool
    {
        return in_array($text, [
            ...self::ACCOUNT_COMMANDS,
            '/batal',
            '/payment',
            ...self::ORDER_STATUS_COMMANDS,
            ...self::ORDER_HISTORY_COMMANDS,
            '/order',
            '/broadcast',
            '📣 Broadcast Info',
            '📊 Statistik',
            '📊 Statistik Harian',
            '📦 Cek Stok',
            '/stok',
        ], true)
            || $this->balanceFunding->isDepositCommand($text)
            || str_starts_with($text, '/payment ')
            || str_starts_with($text, '/order ')
            || $this->isBroadcastCommand($text);
    }

    /** @return array{type: 'product'|'family', id: int}|null */
    private function startIntent(string $text): ?array
    {
        if (preg_match('/^\/start\s+(product|family)_([1-9][0-9]*)$/', $text, $matches) !== 1) {
            return null;
        }

        return [
            'type' => $matches[1],
            'id' => (int) $matches[2],
        ];
    }

    private function isBroadcastCommand(string $text): bool
    {
        return TelegramBroadcastCommand::matches($text);
    }
}
