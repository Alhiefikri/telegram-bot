<?php

namespace App\Services\Telegram;

enum TelegramCallbackStage
{
    case BeforePrivacy;
    case Privileged;
    case Customer;
}
