<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\TelegramNavigationRenderOutcome;
use App\Models\TelegramNavigationRoot;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;

final class TelegramBalanceFundingCallbackHandler implements TelegramCallbackHandler
{
    private const ADD_BALANCE = 'balance:add';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramMenuIntents $menu,
        private readonly StoreBalanceFundingPolicyManager $fundingPolicies,
        private readonly TelegramLocale $locale,
        private readonly TelegramIntentSession $session,
        private readonly TelegramCatalogQuerySession $catalogQueries,
        private readonly TelegramNavigationPresenter $navigation,
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
        return $context->data === self::ADD_BALANCE
            || StoreBalanceFundingPolicyManager::isPresetCallbackFormat($context->data);
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        $root = $this->navigation->matchRoot($context->customer, $context);
        if ($root === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);

        if ($context->data === self::ADD_BALANCE) {
            $outcome = $this->presentChooser($root, $context);
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));

            return true;
        }

        // The callback carries only a signed, versioned identity. The current
        // policy resolves the amount, and a missing identity fails closed.
        $selection = $this->fundingPolicies->resolvePresetCallback($context->data);
        if ($selection === null) {
            $outcome = $this->presentChooser($root, $context);
            $feedback = $this->navigation->feedback($context->customer, $outcome)
                ?? $this->locale->translate($context->customer, 'balance.add.preset_stale');
            $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback);

            return true;
        }

        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'balance.add.selection_unavailable'),
        );

        return true;
    }

    private function presentChooser(TelegramNavigationRoot $root, TelegramCallbackContext $context): TelegramNavigationRenderOutcome
    {
        return $this->navigation->presentOnRoot(
            $root,
            $context->chatId,
            $this->menu->addBalanceSurface($context->customer),
        );
    }
}
