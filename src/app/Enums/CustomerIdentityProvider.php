<?php

namespace App\Enums;

enum CustomerIdentityProvider: string
{
    case Google = 'google';
    case Telegram = 'telegram';
}
