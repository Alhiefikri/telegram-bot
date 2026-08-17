<?php

namespace App\Enums;

enum TelegramOutboundEffectType: string
{
    case ManualCheckoutInstructions = 'checkout.manual_payment_instructions';
}
