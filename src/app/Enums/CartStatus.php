<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case CheckedOut = 'checked_out';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
}
