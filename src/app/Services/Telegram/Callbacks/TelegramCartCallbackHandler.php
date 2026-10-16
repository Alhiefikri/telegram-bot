<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;

class TelegramCartCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCatalogIntents $catalog,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
        private CheckoutMaintenance $maintenance,
        private ProductQuantityPricing $pricing,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === 'batal'
            || preg_match('/^detail:qty:(-|\+|current|manual|cancel|tier):([1-9][0-9]*):[A-Za-z0-9_-]+$/', $context->data) === 1
            || preg_match('/^detail:back:([1-9][0-9]*):[A-Za-z0-9_-]+$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'batal') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forgetWithLock($context->customer);
            $context->customer->carts()->where('origin', CartOrigin::Customer)->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'cart.cancelled'));

            return true;
        }
        if (preg_match('/^detail:qty:(-|\+|current|manual|cancel|tier):([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $context->data, $matches) === 1) {
            $this->handleQuantityAction($context, $matches[1], (int) $matches[2], $matches[3]);

            return true;
        }

        if (preg_match('/^detail:back:([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $context->data, $matches) === 1) {
            $this->handleBack($context, (int) $matches[1], $matches[2]);

            return true;
        }

        return false;
    }

    private function handleQuantityAction(TelegramCallbackContext $context, string $action, int $productId, string $token): void
    {
        $this->session->withLock($context->customer, function () use ($context, $action, $productId, $token): void {
            $selection = $this->currentSelection($context->customer, $productId, $token);
            if ($selection === null) {
                $this->sendStale($context);

                return;
            }

            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->bot->sendMessage(
                    $context->chatId,
                    $this->locale->translate($context->customer, 'checkout.maintenance')
                        .$this->locale->checkoutMaintenanceNote($context->customer, $maintenance->publicNote),
                    ['inline_keyboard' => [[[
                        'text' => $this->locale->translate($context->customer, 'checkout.status_button'),
                        'callback_data' => 'checkout-maintenance:status',
                    ]]]],
                );

                return;
            }

            $product = Product::query()->withAvailability()->publicVisible()->whereKey($productId)->first();
            $availableStock = $product ? max(0, $product->availableStock()) : 0;
            $selectedQuantity = (int) $selection['quantity'];
            if (! $product || $availableStock < 1) {
                $this->session->forget($context->customer);
                $this->sendUnavailable($context);

                return;
            }

            try {
                $quote = $this->pricing->quote($product, min($selectedQuantity, $availableStock));
            } catch (\InvalidArgumentException) {
                $this->session->forget($context->customer);
                $this->sendUnavailable($context);

                return;
            }

            if ($action === 'current') {
                if ($availableStock < $selectedQuantity) {
                    $this->session->forget($context->customer);
                    $this->sendUnavailable($context);

                    return;
                }

                $this->bot->answerCallbackQuery($context->callbackQueryId);

                return;
            }

            if ($action === 'manual') {
                if ($selection['mode'] !== 'browsing') {
                    $this->sendStale($context);

                    return;
                }

                $stockChanged = $selectedQuantity > $availableStock;
                $next = $this->session->awaitCustomQuantity(
                    $context->customer,
                    $stockChanged
                        ? [...$selection, 'quantity' => $availableStock]
                        : $selection,
                    $this->locale->code($context->customer),
                );
                $this->bot->answerCallbackQuery(
                    $context->callbackQueryId,
                    $stockChanged
                        ? $this->locale->translate($context->customer, 'catalog.quantity.stock_changed')
                        : null,
                );
                $this->catalog->sendCustomQuantityPrompt($context->customer, $context->chatId, $product, $next);

                return;
            }

            if ($action === 'cancel') {
                if ($selection['mode'] !== 'awaiting_custom_quantity') {
                    $this->sendStale($context);

                    return;
                }

                $previousQuantity = min((int) $selection['previousQuantity'], $availableStock);
                $stockChanged = $previousQuantity !== (int) $selection['previousQuantity'];
                $next = $this->session->browsing(
                    $context->customer,
                    $selection,
                    $previousQuantity,
                    $this->locale->code($context->customer),
                );
                $this->bot->answerCallbackQuery(
                    $context->callbackQueryId,
                    $stockChanged
                        ? $this->locale->translate($context->customer, 'catalog.quantity.stock_changed')
                        : null,
                );
                $this->catalog->renderProductDetail($context->customer, $context->chatId, $product, $next, $availableStock, $context->messageId);

                return;
            }

            if ($selection['mode'] !== 'browsing') {
                $this->sendStale($context);

                return;
            }

            // A stale detail prompt may still contain a quantity above live
            // stock. Re-render the highest valid selection instead of leaving
            // the old payment keyboard actionable.
            if ($selectedQuantity > $availableStock) {
                $next = $this->session->browsing(
                    $context->customer,
                    $selection,
                    $availableStock,
                    $this->locale->code($context->customer),
                );
                $this->bot->answerCallbackQuery(
                    $context->callbackQueryId,
                    $this->locale->translate($context->customer, 'catalog.quantity.stock_changed'),
                );
                $this->catalog->renderProductDetail($context->customer, $context->chatId, $product, $next, $availableStock, $context->messageId);

                return;
            }

            if ($action === 'tier') {
                $nextTier = $quote->nextTier;
                if ($nextTier === null || $nextTier->minimumQuantity > $availableStock) {
                    $this->sendStale($context);

                    return;
                }

                $next = $this->session->browsing(
                    $context->customer,
                    $selection,
                    $nextTier->minimumQuantity,
                    $this->locale->code($context->customer),
                );
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->catalog->renderProductDetail($context->customer, $context->chatId, $product, $next, $availableStock, $context->messageId);

                return;
            }

            $quantity = $selectedQuantity + ($action === '+' ? 1 : -1);
            if ($quantity < 1) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.quantity.minimum'));

                return;
            }
            if ($quantity > $availableStock) {
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.quantity.maximum', ['stock' => $availableStock]));

                return;
            }
            try {
                $this->pricing->quote($product, $quantity);
            } catch (\InvalidArgumentException) {
                $this->session->forget($context->customer);
                $this->sendUnavailable($context);

                return;
            }

            $next = $this->session->browsing(
                $context->customer,
                $selection,
                $quantity,
                $this->locale->code($context->customer),
            );
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->catalog->renderProductDetail($context->customer, $context->chatId, $product, $next, $availableStock, $context->messageId);
        });
    }

    private function handleBack(TelegramCallbackContext $context, int $productId, string $token): void
    {
        // The selection is validated and released while the interaction lock is
        // held; the destination screen renders outside it, because the lock is
        // not reentrant and rendering a list releases the selection again.
        $selection = $this->session->withLock($context->customer, function () use ($context, $productId, $token): ?array {
            $selection = $this->currentSelection($context->customer, $productId, $token, ['browsing']);
            if ($selection === null) {
                return null;
            }
            $this->session->forget($context->customer);

            return $selection;
        });
        if ($selection === null) {
            $this->sendStale($context);

            return;
        }

        $familyId = $selection['familyId'] ?? null;
        $familyPage = $selection['familyPage'] ?? null;
        $familyListPage = $selection['familyListPage'] ?? 1;
        if (is_int($familyId) && $familyId > 0 && is_int($familyPage) && $familyPage > 0
            && is_int($familyListPage) && $familyListPage > 0) {
            $opened = $this->catalog->openFamilyOptions(
                $context->customer,
                $context->chatId,
                $familyId,
                $familyPage,
                returnPage: $familyListPage,
                expectedProductId: $productId,
                originToken: is_string($selection['familyOriginToken'] ?? null) ? $selection['familyOriginToken'] : null,
                messageId: $context->messageId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $opened ? null : $this->locale->translate($context->customer, 'catalog.selection.stale'),
            );

            return;
        }
        $originToken = is_string($selection['originToken'] ?? null) ? $selection['originToken'] : null;
        if ($originToken !== null
            && $this->catalog->openCatalogOrigin($context->customer, $context->chatId, $originToken, $context->messageId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return;
        }
        $product = Product::whereKey($productId)->first();
        if (! $product) {
            $this->sendUnavailable($context);

            return;
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->catalog->sendCategoryProducts(
            $context->customer,
            $context->chatId,
            $product->category_id ? (string) $product->category_id : 'uncategorized',
            1,
            $context->messageId,
        );
    }

    /** @param  list<string>  $modes */
    private function currentSelection(Customer $customer, int $productId, string $token, array $modes = ['browsing', 'awaiting_custom_quantity']): ?array
    {
        $selection = $this->session->get($customer);
        if ($selection === null
            || (int) $selection['productId'] !== $productId
            || ! hash_equals((string) $selection['interactionToken'], $token)
            || ! in_array($selection['mode'], $modes, true)
        ) {
            return null;
        }

        return $selection;
    }

    private function sendStale(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));
    }

    private function sendUnavailable(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.unavailable'));
    }
}
