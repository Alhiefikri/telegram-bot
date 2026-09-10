<?php

namespace App\Enums;

enum TelegramNavigationScreen: string
{
    case Picker = 'picker';
    case Home = 'home';
    case Balance = 'balance';
    case Orders = 'orders';
    case Support = 'support';
    case Catalog = 'catalog';
}
