<?php

namespace App\Enums;

enum TelegramOutboundEffectType: string
{
    case ManualCheckoutInstructions = 'checkout.manual_payment_instructions';
    case KlikQrisCheckoutInstructions = 'checkout.klikqris_payment_instructions';
    case PakasirCheckoutInstructions = 'checkout.pakasir_payment_instructions';
    case BinanceCheckoutInstructions = 'checkout.binance_payment_instructions';
    case ReceiptCredentials = 'receipt.credentials';
}
