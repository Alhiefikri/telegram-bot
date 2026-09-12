<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramNavigationSurface;
use App\Services\Telegram\TelegramOrderIntents;

/**
 * Presents every routine customer menu screen on the canonical message. The
 * handler only selects a surface; every screen keeps its owning service.
 */
class TelegramHomeCallbackHandler implements TelegramCallbackHandler
{
    private const ACTIONS = [
        'menu:home',
        'menu:back',
        'menu:orders',
        'menu:support',
        'menu:language',
        'balance',
    ];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramMenuIntents $menu,
        private TelegramOrderIntents $orders,
        private TelegramLocale $locale,
        private TelegramIntentSession $session,
        private TelegramCatalogQuerySession $catalogQueries,
        private TelegramNavigationPresenter $navigation,
        private StoreBalanceTopUpOutbound $topUpOutbound,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return in_array($context->data, self::ACTIONS, true);
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        $root = $this->navigation->matchRoot($context->customer, $context);
        if ($root === null
            && in_array($context->data, ['balance', 'menu:home', 'menu:support'], true)
            && $context->messageId !== null
            && $this->topUpOutbound->hasSentReceiptFor(
                $context->customer,
                $context->chatId,
                $context->messageId,
            )) {
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $outcome = $this->navigation->presentOnCurrentRoot(
                $context->customer,
                $context->chatId,
                $this->surfaceFor($context),
            );
            if ($outcome !== null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

                return true;
            }
        }

        if ($root === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);

        $outcome = $this->navigation->presentOnRoot($root, $context->chatId, $this->surfaceFor($context));

        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

        return true;
    }

    private function surfaceFor(TelegramCallbackContext $context): TelegramNavigationSurface
    {
        return match ($context->data) {
            'menu:orders' => $this->orders->orderHistorySurface($context->customer),
            'menu:support' => $this->menu->supportSurface($context->customer),
            'menu:language' => $this->menu->languageSurface($context->customer),
            'balance' => $this->menu->balanceSurface($context->customer),
            default => $this->menu->homeSurface($context->customer, $context->chatId),
        };
    }
}
