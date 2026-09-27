<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\CatalogBrandIcon;
use App\Models\TelegramUiIcon;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramIconIntents;
use App\Services\Telegram\TelegramLocale;

class TelegramIconCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private TelegramIconIntents $icons,
        private TelegramLocale $locale,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Privileged;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return str_starts_with($context->data, 'brandicon:') || str_starts_with($context->data, 'menuicon:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->adminAccess->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'router.access_denied'));

            return true;
        }

        if ($context->data === 'brandicon:list') {
            $this->icons->showBrands($context->customer, $context->chatId, $context->messageId);

            return true;
        }

        if ($context->data === 'menuicon:list') {
            $this->icons->showMenuIcons($context->customer, $context->chatId, $context->messageId);

            return true;
        }

        if (preg_match('/\A(brandicon|menuicon):(pick|clear):([0-9]+)\z/', $context->data, $matches) !== 1) {
            return false;
        }

        return $matches[1] === 'menuicon'
            ? $this->handleMenuIcon($context, (int) $matches[3], $matches[2] === 'clear')
            : $this->handleBrand($context, (int) $matches[3], $matches[2] === 'clear');
    }

    private function handleBrand(TelegramCallbackContext $context, int $brandId, bool $clear): bool
    {
        $brand = CatalogBrandIcon::query()->find($brandId);
        if (! $brand instanceof CatalogBrandIcon) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.brand_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearBrand($context->customer, $context->chatId, $brand, $context->messageId);
        } else {
            $this->icons->promptBrand($context->customer, $context->chatId, $brand, $context->messageId);
        }

        return true;
    }

    private function handleMenuIcon(TelegramCallbackContext $context, int $iconId, bool $clear): bool
    {
        $icon = TelegramUiIcon::query()->find($iconId);
        if (! $icon instanceof TelegramUiIcon) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.brand_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearMenuIcon($context->customer, $context->chatId, $icon, $context->messageId);
        } else {
            $this->icons->promptMenuIcon($context->customer, $context->chatId, $icon, $context->messageId);
        }

        return true;
    }
}
