<?php

namespace App\Enums;

enum SupplierProvider: string
{
    case ProdSeller = 'prodseller';
    case CSxStore = 'csxstore';
    case Arrs = 'arrs';
    case Warzone = 'warzone';
    case AiVault = 'aivault';
    case NyStore = 'nystore';
    case ToolOrax = 'toolorax';
    case BuyMail = 'buymail';
    case Canboso = 'canboso';

    public function baseUrl(): string
    {
        return match ($this) {
            self::ProdSeller => 'https://prodseller.com/v1',
            self::CSxStore => 'https://api.cssx.store/api/v1',
            self::Arrs => 'https://arrsnetworkzone.in/api/v1',
            self::Warzone => 'https://api.warzoneshop.in/api/v1',
            self::AiVault => 'https://reseller.aivaulthub.store/api/v1',
            self::NyStore => 'https://nystoreofficial.shop/api/v1',
            self::ToolOrax => 'https://shopbot.00969600.xyz/shop-api/v1',
            self::BuyMail => 'https://buymail.simuru-store.com/api/reseller',
            self::Canboso => 'https://canboso.com/api/v2/telegram-buyer',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProdSeller => 'ProdSeller',
            self::CSxStore => 'CSxStore',
            self::Arrs => 'ARRS',
            self::Warzone => 'Warzone',
            self::AiVault => 'AI Vault Hub',
            self::NyStore => 'NyStore Official',
            self::ToolOrax => 'ToolOraX (ShopBot)',
            self::BuyMail => 'BuyMail',
            self::Canboso => 'Canboso',
        };
    }

    public function supportsIdempotency(): bool
    {
        return in_array($this, [self::ProdSeller, self::AiVault, self::NyStore, self::BuyMail, self::Canboso], true);
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::ProdSeller => 'USDT',
            self::BuyMail => 'IDR',
            default => 'USD',
        };
    }

    public function requiresCurrencyConfirmation(): bool
    {
        return in_array($this, [self::Arrs, self::Warzone], true);
    }
}
