<?php

namespace App\Services\Telegram;

interface TelegramCallbackHandler
{
    public function stage(): TelegramCallbackStage;

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool;

    public function supports(TelegramCallbackContext $context): bool;

    public function handle(TelegramCallbackContext $context): bool;
}
