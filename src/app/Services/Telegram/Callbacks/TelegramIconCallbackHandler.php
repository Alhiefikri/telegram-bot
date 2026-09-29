<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Product;
use App\Models\ProductFamily;
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
        return str_starts_with($context->data, 'producticon:')
            || str_starts_with($context->data, 'familyicon:')
            || str_starts_with($context->data, 'menuicon:')
            || str_starts_with($context->data, 'messageicon:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->adminAccess->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'router.access_denied'));

            return true;
        }

        if ($context->data === 'producticon:list') {
            $this->icons->showProducts($context->customer, $context->chatId, $context->messageId);

            return true;
        }

        if ($context->data === 'menuicon:list') {
            $this->icons->showMenuIcons($context->customer, $context->chatId, $context->messageId);

            return true;
        }

        if ($context->data === 'messageicon:list') {
            $this->icons->showMessageIcons($context->customer, $context->chatId, $context->messageId);

            return true;
        }

        if (preg_match('/\A(producticon|familyicon|menuicon|messageicon):(pick|clear):([0-9]+)\z/', $context->data, $matches) !== 1) {
            return false;
        }

        return match ($matches[1]) {
            'familyicon' => $this->handleFamilyIcon($context, (int) $matches[3], $matches[2] === 'clear'),
            'menuicon' => $this->handleMenuIcon($context, (int) $matches[3], $matches[2] === 'clear'),
            'messageicon' => $this->handleMessageIcon($context, (int) $matches[3], $matches[2] === 'clear'),
            default => $this->handleProduct($context, (int) $matches[3], $matches[2] === 'clear'),
        };
    }

    private function handleFamilyIcon(TelegramCallbackContext $context, int $familyId, bool $clear): bool
    {
        $family = ProductFamily::query()->find($familyId);
        if (! $family instanceof ProductFamily) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearFamily($context->customer, $context->chatId, $family, $context->messageId);
        } else {
            $this->icons->promptFamily($context->customer, $context->chatId, $family, $context->messageId);
        }

        return true;
    }

    private function handleProduct(TelegramCallbackContext $context, int $productId, bool $clear): bool
    {
        $product = Product::query()->find($productId);
        if (! $product instanceof Product) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearProduct($context->customer, $context->chatId, $product, $context->messageId);
        } else {
            $this->icons->promptProduct($context->customer, $context->chatId, $product, $context->messageId);
        }

        return true;
    }

    private function handleMenuIcon(TelegramCallbackContext $context, int $iconId, bool $clear): bool
    {
        $icon = TelegramUiIcon::query()->find($iconId);
        if (! $icon instanceof TelegramUiIcon) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearMenuIcon($context->customer, $context->chatId, $icon, $context->messageId);
        } else {
            $this->icons->promptMenuIcon($context->customer, $context->chatId, $icon, $context->messageId);
        }

        return true;
    }

    private function handleMessageIcon(TelegramCallbackContext $context, int $iconId, bool $clear): bool
    {
        $icon = TelegramUiIcon::query()
            ->where('key', 'like', 'message.%')
            ->find($iconId);
        if (! $icon instanceof TelegramUiIcon) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.product_icon.expired'));

            return true;
        }

        if ($clear) {
            $this->icons->clearMessageIcon($context->customer, $context->chatId, $icon, $context->messageId);
        } else {
            $this->icons->promptMessageIcon($context->customer, $context->chatId, $icon, $context->messageId);
        }

        return true;
    }
}
