<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationRenderOutcome;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;

/**
 * Presentation seam for routine customer navigation on the canonical message.
 *
 * Callback navigation may only commit to the root the callback really targets;
 * any other message is stale and stays fail-closed. Content built without an
 * originating message edits the current root when one exists.
 */
final class TelegramNavigationPresenter
{
    public function __construct(
        private TelegramNavigationRootService $roots,
        private TelegramLocale $locale,
        private TelegramBotClient $bot,
    ) {}

    /**
     * Resolve the canonical root only when this callback really targets it.
     * Callers that must prove ownership before mutating state can gate on the
     * result and then present on it.
     */
    public function matchRoot(Customer $customer, TelegramCallbackContext $context): ?TelegramNavigationRoot
    {
        return $this->roots->match($customer, $context->chatId, $context->messageId);
    }

    public function presentOnRoot(TelegramNavigationRoot $root, string $chatId, TelegramNavigationSurface $surface): TelegramNavigationRenderOutcome
    {
        return $this->roots->render($root, $chatId, $surface);
    }

    public function presentFromCallback(Customer $customer, TelegramCallbackContext $context, TelegramNavigationSurface $surface): TelegramNavigationRenderOutcome
    {
        $root = $this->matchRoot($customer, $context);
        if ($root === null) {
            return TelegramNavigationRenderOutcome::Stale;
        }

        return $this->roots->render($root, $context->chatId, $surface);
    }

    /**
     * Edit the current canonical root of this chat, or report null when the
     * caller has to keep its legacy send path.
     */
    public function presentOnCurrentRoot(Customer $customer, string $chatId, TelegramNavigationSurface $surface): ?TelegramNavigationRenderOutcome
    {
        $root = $this->roots->currentForChat($customer, $chatId);
        if ($root === null) {
            return null;
        }

        return $this->roots->render($root, $chatId, $surface);
    }

    /**
     * Routine navigation without an originating message: edit the current
     * canonical root, or send once when this chat has no root yet.
     */
    public function presentOrSend(Customer $customer, string $chatId, TelegramNavigationSurface $surface, bool $privateChat = true): void
    {
        if ($privateChat && $this->presentOnCurrentRoot($customer, $chatId, $surface) !== null) {
            return;
        }

        $this->bot->sendMessage($chatId, $surface->caption, $surface->keyboard, $surface->options);
    }

    public function feedback(Customer $customer, TelegramNavigationRenderOutcome $outcome): ?string
    {
        return match ($outcome) {
            TelegramNavigationRenderOutcome::Ambiguous => $this->locale->translate($customer, 'callback.navigation_recovery'),
            TelegramNavigationRenderOutcome::Stale,
            TelegramNavigationRenderOutcome::Superseded => $this->locale->translate($customer, 'callback.navigation_stale'),
            default => null,
        };
    }
}
