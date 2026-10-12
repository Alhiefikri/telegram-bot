<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramFamilyCallbackToken;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramNavigationRootService;

class TelegramCatalogCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCatalogIntents $catalog,
        private TelegramCatalogQuerySession $catalogQueries,
        private TelegramFamilyCallbackToken $familyTokens,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
        private TelegramMenuIntents $menu,
        private TelegramNavigationPresenter $navigation,
        private TelegramNavigationRootService $roots,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return $this->supports($context);
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return in_array($context->data, ['katalog', 'back:categories', 'catalog:categories', 'catalog:refresh', 'family:back:menu'], true)
            || preg_match('/^category:([1-9][0-9]*|uncategorized)(?::page:[1-9][0-9]*)?$/', $context->data) === 1
            || preg_match('/^family:list:[1-9][0-9]{0,18}(?::o[a-f0-9]{16})?$/', $context->data) === 1
            || preg_match('/^family:search:[a-f0-9]{16}:[1-9][0-9]{0,18}$/', $context->data) === 1
            || preg_match('/^family:options:v[A-Za-z0-9_-]{1,48}(?::o[a-f0-9]{16}|:s[a-f0-9]{16})?$/', $context->data) === 1
            || preg_match('/^family:select:v[A-Za-z0-9_-]{1,48}$/', $context->data) === 1
            || preg_match('/^product:([1-9][0-9]*)(?::o[a-f0-9]{16})?$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'family:back:menu') {
            $this->handleBackToMenu($context);

            return true;
        }

        if ($context->data === 'catalog:refresh') {
            $root = $context->isPrivate()
                ? $this->roots->currentForChat($context->customer, $context->chatId)
                : null;
            if ($context->messageId === null || ($root !== null && $this->navigation->matchRoot($context->customer, $context) === null)) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'callback.navigation_stale'));

                return true;
            }
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCatalogPage($context->customer, $context->chatId, messageId: $context->messageId, refresh: true);

            return true;
        }

        if ($context->data === 'catalog:categories') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCategoryPicker($context->customer, $context->chatId, messageId: $context->messageId);

            return true;
        }

        if ($context->data === 'back:categories') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCategoryPicker($context->customer, $context->chatId, messageId: $context->messageId);

            return true;
        }

        if ($context->data === 'katalog') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCategoryCatalog($context->customer, $context->chatId, messageId: $context->messageId);

            return true;
        }

        if (preg_match('/^family:list:([1-9][0-9]{0,18}):o([a-f0-9]{16})$/', $context->data, $matches)) {
            $opened = $this->catalog->openCatalogOrigin($context->customer, $context->chatId, $matches[2], $context->messageId);
            if ($opened) {
                $this->session->forgetWithLock($context->customer);
            }
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $opened ? null : $this->locale->translate($context->customer, 'catalog.selection.stale'),
            );

            return true;
        }

        if (preg_match('/^family:list:([1-9][0-9]{0,18})$/', $context->data, $matches)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCatalogPage($context->customer, $context->chatId, (int) $matches[1], messageId: $context->messageId);

            return true;
        }

        if (preg_match('/^family:search:([a-f0-9]{16}):([1-9][0-9]{0,18})$/', $context->data, $matches)) {
            $search = $this->catalogQueries->searchForToken($context->customer, $matches[1]);
            if ($search === null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));

                return true;
            }
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalog->sendSearchResults($context->customer, $context->chatId, $search, (int) $matches[2], $context->messageId);

            return true;
        }

        if (str_starts_with($context->data, 'family:options:')) {
            $payload = $this->decodeOptionsCallback($context->data);
            if ($payload === null || (
                ! isset($payload['originToken'])
                && ! isset($payload['stateToken'])
                && ! $this->acceptsUnscopedFamilyOptions($context->customer, $payload)
            )) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));

                return true;
            }
            $opened = $this->catalog->openFamilyOptions(
                $context->customer,
                $context->chatId,
                $payload['familyId'],
                $payload['page'],
                $payload['returnPage'],
                originToken: $payload['originToken'] ?? null,
                stateToken: $payload['stateToken'] ?? null,
                beforeSend: function () use ($context): void {
                    $this->session->forgetWithLock($context->customer);
                },
                messageId: $context->messageId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $opened ? null : $this->locale->translate($context->customer, 'catalog.selection.stale'),
            );

            return true;
        }
        if (str_starts_with($context->data, 'family:select:')) {
            $payload = $this->decodeSelectCallback($context->data);
            if ($payload === null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));

                return true;
            }
            $opened = $this->session->withLock($context->customer, fn (): bool => $this->catalog->openFamilyProduct(
                $context->customer,
                $context->chatId,
                $payload['familyId'],
                $payload['productId'],
                $payload['page'],
                $payload['returnPage'],
                $context->messageId,
            ));
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $opened ? null : $this->locale->translate($context->customer, 'catalog.selection.stale'),
            );

            return true;
        }

        if (preg_match('/^category:([1-9][0-9]*|uncategorized)(?::page:([1-9][0-9]*))?$/', $context->data, $matches)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $this->catalogQueries->forget($context->customer);
            $this->catalog->sendCategoryProducts(
                $context->customer,
                $context->chatId,
                $matches[1],
                isset($matches[2]) ? (int) $matches[2] : 1,
                $context->messageId,
            );

            return true;
        }

        if (preg_match('/^product:([1-9][0-9]*)(?::o([a-f0-9]{16}))?$/', $context->data, $matches)) {
            $originToken = $matches[2] ?? null;
            if ($originToken !== null && $this->catalogQueries->stateForToken($context->customer, $originToken) === null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));

                return true;
            }
            $product = Product::query()->with(['quantityPriceTiers'])->withAvailability()->publicVisible()->whereKey((int) $matches[1])->first();
            if (! $product) {
                $this->session->forgetWithLock($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_unavailable'));

                return true;
            }
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            if ($originToken === null) {
                $this->catalogQueries->forget($context->customer);
            }
            $this->session->forgetWithLock($context->customer);
            $this->catalog->sendProductDetail(
                $context->customer,
                $context->chatId,
                $product,
                $originToken === null ? null : ['originToken' => $originToken],
                $context->messageId,
            );

            return true;
        }

        return false;
    }

    /**
     * Catalog screens keep their "back to menu" affordance, but the menu is
     * now the canonical home of this chat. Groups only get the public-safe
     * surface and never a private root.
     */
    private function handleBackToMenu(TelegramCallbackContext $context): void
    {
        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);

        if (! $context->isPrivate()) {
            $surface = $this->menu->groupSurface($context->customer);
            $this->bot->editMessageTextOrSend(
                $context->chatId,
                $surface->caption,
                $surface->keyboard,
                $context->messageId,
                $surface->options,
            );
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return;
        }

        $outcome = $this->navigation->presentFromCallback(
            $context->customer,
            $context,
            $this->menu->homeSurface($context->customer, $context->chatId),
        );
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->navigation->feedback($context->customer, $outcome));
    }

    /** @param array{familyId:int,page:int,returnPage:int,originToken?:string,stateToken?:string} $payload */
    private function acceptsUnscopedFamilyOptions(Customer $customer, array $payload): bool
    {
        $state = $this->catalogQueries->state($customer);
        if ($state === null) {
            return false;
        }

        return ($state['type'] ?? null) === 'family'
            && ($state['familyId'] ?? null) === $payload['familyId']
            && ($state['returnPage'] ?? null) === $payload['returnPage']
            && ! is_array($state['origin'] ?? null);
    }

    /** @return array{familyId:int,page:int,returnPage:int,originToken?:string,stateToken?:string}|null */
    private function decodeOptionsCallback(string $data): ?array
    {
        if (preg_match('/^family:options:v([A-Za-z0-9_-]{1,48})(?::o([a-f0-9]{16})|:s([a-f0-9]{16}))?$/', $data, $matches) === 1) {
            $payload = $this->familyTokens->decodeOptions($matches[1]);
            if ($payload === null) {
                return null;
            }
            if (isset($matches[2]) && $matches[2] !== '') {
                $payload['originToken'] = $matches[2];
            }
            if (isset($matches[3]) && $matches[3] !== '') {
                $payload['stateToken'] = $matches[3];
            }

            return $payload;
        }

        return null;
    }

    /** @return array{familyId:int,productId:int,page:int,returnPage:int}|null */
    private function decodeSelectCallback(string $data): ?array
    {
        if (preg_match('/^family:select:v([A-Za-z0-9_-]{1,48})$/', $data, $matches) === 1) {
            return $this->familyTokens->decodeSelect($matches[1]);
        }

        return null;
    }
}
