<?php

namespace App\Enums;

enum TelegramNavigationRenderOutcome
{
    case Applied;
    case Unchanged;
    case Superseded;
    case Replaced;
    case Ambiguous;
}
