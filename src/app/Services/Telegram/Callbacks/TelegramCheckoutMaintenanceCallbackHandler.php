<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Customer;
use App\Services\CartService;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;

final class TelegramCheckoutMaintenanceCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private CheckoutMaintenance $maintenance,
        private TelegramLocale $locale,
        private CartService $carts,
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
        return $context->data === 'checkout-maintenance:status';
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $state = $this->maintenance->current();
        $this->bot->answerCallbackQuery($context->callbackQueryId);

        if ($state->active) {
            $this->bot->sendMessage(
                $context->chatId,
                $this->activeMessage($context->customer, $state),
                ['inline_keyboard' => [[[
                    'text' => $this->locale->translate($context->customer, 'checkout.status_again'),
                    'callback_data' => 'checkout-maintenance:status',
                ]]]],
            );

            return true;
        }

        $this->bot->sendMessage(
            $context->chatId,
            $this->locale->translate($context->customer, 'checkout.reopened'),
            ['inline_keyboard' => $this->reopenedKeyboard($context->customer)],
        );

        return true;
    }

    private function activeMessage(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->locale->translate($customer, 'checkout.maintenance')
            .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote);
    }

    /** @return list<list<array<string, string>>> */
    private function reopenedKeyboard(Customer $customer): array
    {
        $keyboard = [[[
            'text' => $this->locale->translate($customer, 'checkout.browse_button'),
            'callback_data' => 'katalog',
        ]]];

        $cart = $this->carts->currentActiveCartFor($customer);
        if ($cart?->cartItems()->exists()) {
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'checkout.continue_button'),
                'callback_data' => 'checkout',
            ]];
        }

        $keyboard[] = [[
            'text' => $this->locale->translate($customer, 'checkout.orders_button'),
            'callback_data' => 'status',
        ]];

        return $keyboard;
    }
}
