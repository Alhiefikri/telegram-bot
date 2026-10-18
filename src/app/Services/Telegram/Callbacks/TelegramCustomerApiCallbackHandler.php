<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\BalancePaymentPreference;
use App\Services\CustomerApi\CustomerApiAvailability;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\IssuedCustomerApiKey;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCustomerApiActions;
use App\Services\Telegram\TelegramCustomerApiIntents;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramNavigationPresenter;
use Throwable;

final class TelegramCustomerApiCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramCustomerApiActions $actions,
        private TelegramCustomerApiIntents $intents,
        private TelegramNavigationPresenter $navigation,
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private CustomerApiAvailability $availability,
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
        return str_starts_with($context->data, 'api:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $root = $this->navigation->matchRoot($context->customer, $context);
        if (! $context->isPrivate() || (string) $context->customer->telegram_id !== $context->chatId || $root === null) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'callback.navigation_stale'));

            return true;
        }

        // OFF is fail-closed: no key, preference, secret or summary action may
        // run, including for an api:* callback rendered before the change.
        if (! $this->availability->isEnabled()) {
            $surface = $this->intents->maintenance($context->customer);
            $this->navigation->presentOnRoot($root, $context->chatId, $surface);
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'account.api.maintenance_callback'),
            );

            return true;
        }

        $facts = [(string) $context->customer->telegram_id, $context->chatId, $context->chatType, $context->messageId];
        $notice = null;
        try {
            if ($context->data === 'api:rotate' || $context->data === 'api:disable') {
                $rotate = $context->data === 'api:rotate';
                $nonce = $rotate ? $this->actions->prepareRotation(...$facts) : $this->actions->prepareDisable(...$facts);
                $surface = $this->intents->confirmation($context->customer, $rotate ? 'rotate' : 'disable', $nonce);
            } elseif ($context->data === 'api:preferences') {
                $surface = $this->intents->preferences($context->customer);
            } else {
                if ($context->data === 'api:generate') {
                    $notice = $this->display($context, $this->actions->generate(...$facts));
                } elseif (preg_match('/\Aapi:(rotate|disable):([a-f0-9]{32})\z/D', $context->data, $matches) === 1) {
                    if ($matches[1] === 'rotate') {
                        $notice = $this->display($context, $this->actions->rotate(...[...$facts, $matches[2]]));
                    } else {
                        $this->actions->disable(...[...$facts, $matches[2]]);
                    }
                } elseif (str_starts_with($context->data, 'api:preference:')) {
                    $preference = BalancePaymentPreference::tryFrom(substr($context->data, strlen('api:preference:')));
                    if ($preference === null) {
                        throw new CustomerApiFailure(409, 'stale_key_action', 'Invalid preference.');
                    }
                    $this->actions->preference(...[...$facts, $preference]);
                } elseif ($context->data !== 'api:menu') {
                    throw new CustomerApiFailure(409, 'stale_key_action', 'Unknown API action.');
                }
                $surface = $this->intents->management($context->customer, $this->actions->summary(...$facts), $notice);
            }
            $outcome = $this->navigation->presentOnRoot($root, $context->chatId, $surface);
            $feedback = $this->navigation->feedback($context->customer, $outcome);
        } catch (CustomerApiFailure $failure) {
            $feedback = $this->locale->translate($context->customer, match ($failure->status) {
                429 => 'account.api.rate_limited',
                503 => 'account.api.unavailable',
                default => 'account.api.stale',
            });
        }
        $this->bot->answerCallbackQuery($context->callbackQueryId, $feedback);

        return true;
    }

    private function display(TelegramCallbackContext $context, IssuedCustomerApiKey $issued): string
    {
        // ADR-0007: one memory-only send, outside transactions, with no recovery or secret-bearing exception report.
        try {
            $response = $this->bot->sendMessage($context->chatId, $this->intents->secretMessage($context->customer, $issued->takeSecret()), [], ['parse_mode' => 'HTML']);
            if (($response['ok'] ?? false) === true && is_int($response['result']['message_id'] ?? null) && $response['result']['message_id'] > 0) {
                return 'account.api.displayed';
            }
        } catch (Throwable) {
            // Telegram may have accepted the message. Never retry or persist the secret.
        }

        return 'account.api.display_failed';
    }
}
