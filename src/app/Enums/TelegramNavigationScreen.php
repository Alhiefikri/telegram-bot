<?php

namespace App\Enums;

enum TelegramNavigationScreen: string
{
    case Picker = 'picker';
    case Home = 'home';
    case Balance = 'balance';
    case AddBalance = 'add_balance';
    case UsdtFundingAmount = 'usdt_funding_amount';
    case UsdtBalance = 'usdt_balance';
    case Orders = 'orders';
    case Support = 'support';
    case Catalog = 'catalog';
    case Product = 'product';
}
