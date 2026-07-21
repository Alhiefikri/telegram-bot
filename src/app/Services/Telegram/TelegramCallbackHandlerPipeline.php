<?php

namespace App\Services\Telegram;

class TelegramCallbackHandlerPipeline
{
    /**
     * @param  iterable<TelegramCallbackHandler>  $handlers
     */
    public function __construct(private iterable $handlers) {}

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($context) && $handler->allowsNonPrivateChat($context)) {
                return true;
            }
        }

        return false;
    }

    public function dispatch(TelegramCallbackContext $context, ?TelegramCallbackStage $stage = null): bool
    {
        foreach ($this->handlers as $handler) {
            if ($stage !== null && $handler->stage() !== $stage) {
                continue;
            }

            if (! $handler->supports($context)) {
                continue;
            }

            if ($handler->handle($context)) {
                return true;
            }
        }

        return false;
    }
}
