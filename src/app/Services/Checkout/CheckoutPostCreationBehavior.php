<?php

namespace App\Services\Checkout;

enum CheckoutPostCreationBehavior
{
    case ManualQrisInstructions;
    case BinancePayInstructions;
    case PakasirInstructions;
}
