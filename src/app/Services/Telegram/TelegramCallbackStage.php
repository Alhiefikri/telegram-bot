<?php

namespace App\Services\Telegram;

enum TelegramCallbackStage
{
    case BeforePrivacy;
    case BeforeCommunityGate;
    case AfterCommunityGate;
}
