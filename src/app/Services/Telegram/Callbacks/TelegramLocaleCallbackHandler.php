<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\TelegramNavigationRenderOutcome;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCommunityGate;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramStartIntentSession;

class TelegramLocaleCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCommunityGate $communityGate,
        private TelegramCatalogIntents $catalog,
        private TelegramMenuIntents $menu,
        private TelegramIntentSession $session,
        private TelegramStartIntentSession $startIntents,
        private TelegramLocale $locale,
        private TelegramNavigationRootService $roots,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::BeforePrivacy;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $this->locale->localeFromCallbackData($context->data) !== null;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $locale = $this->locale->localeFromCallbackData($context->data);
        if ($locale === null) {
            return false;
        }

        if (! $context->isPrivate()) {
            return false;
        }

        $root = $this->roots->match($context->customer, $context->chatId, $context->messageId);
        if ($root === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->communityGate->clearPrompt($context->customer);
        $this->session->forgetWithLock($context->customer);
        if ($context->customer->locale !== $locale) {
            $context->customer->update(['locale' => $locale]);
            $context->customer->refresh();
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return true;
        }

        $pendingIntent = $this->startIntents->pull($context->customer);
        if ($pendingIntent !== null && $this->catalog->openStartIntent($context->customer, $context->chatId, $pendingIntent)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return true;
        }

        $outcome = $this->menu->renderHome($root, $context->customer, $context->chatId);

        $this->bot->answerCallbackQuery($context->callbackQueryId, match ($outcome) {
            TelegramNavigationRenderOutcome::Ambiguous => $this->locale->translate($context->customer, 'callback.navigation_recovery'),
            TelegramNavigationRenderOutcome::Superseded => $this->locale->translate($context->customer, 'callback.navigation_stale'),
            default => null,
        });

        return true;
    }
}
