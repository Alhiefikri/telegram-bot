<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Product;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;

class TelegramCatalogCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCatalogIntents $catalog,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return $this->supports($context);
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return in_array($context->data, ['katalog', 'back:categories', 'balance'], true)
            || preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $context->data) === 1
            || preg_match('/^product:([1-9][0-9]*)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'katalog' || $context->data === 'back:categories') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forget($context->customer);
            $this->catalog->sendCategoryCatalog($context->customer, $context->chatId);

            return true;
        }

        if (preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $context->data, $matches)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forget($context->customer);
            $this->catalog->sendCategoryProducts($context->customer, $context->chatId, $matches[1]);

            return true;
        }

        if ($context->data === 'balance') {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.balance_soon'));

            return true;
        }

        if (preg_match('/^product:([1-9][0-9]*)$/', $context->data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_unavailable'));

                return true;
            }

            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forget($context->customer);
            $this->catalog->sendStockChoices($context->customer, $context->chatId, $product, $availableStock);

            return true;
        }

        return false;
    }
}
