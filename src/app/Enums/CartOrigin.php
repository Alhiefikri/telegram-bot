<?php

namespace App\Enums;

/**
 * Which purchase surface opened a Cart. API checkout inputs are isolated from
 * the Customer's Telegram/Web Cart: every customer-facing cart read and write
 * filters to the customer origin, so an API Cart can never be shown, resumed,
 * edited, or abandoned by the bot or the Web Storefront. Isolated Telegram
 * Direct and Storefront Balance Carts likewise never replace or consume the
 * Customer's active Cart.
 */
enum CartOrigin: string
{
    case Customer = 'customer';
    case Api = 'api';
    case TelegramDirect = 'telegram_direct';
    case StorefrontBalance = 'web_balance';
}
