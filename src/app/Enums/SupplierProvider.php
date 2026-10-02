<?php

namespace App\Enums;

enum SupplierProvider: string
{
    case ProdSeller = 'prodseller';
    case CSxStore = 'csxstore';
    case Arrs = 'arrs';
    case Warzone = 'warzone';
    case AiVault = 'aivault';

    public function baseUrl(): string
    {
        return match ($this) {
            self::ProdSeller => 'https://prodseller.com/v1',
            self::CSxStore => 'https://api.cssx.store/api/v1',
            self::Arrs => 'https://arrsnetworkzone.in/api/v1',
            self::Warzone => 'https://api.warzoneshop.in/api/v1',
            self::AiVault => 'https://reseller.aivaulthub.store/api/v1',
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
        };
    }

    public function supportsIdempotency(): bool
    {
        return in_array($this, [self::ProdSeller, self::AiVault], true);
    }

    public function defaultCurrency(): string
    {
        return $this === self::ProdSeller ? 'USDT' : 'USD';
    }

    public function requiresCurrencyConfirmation(): bool
    {
        return in_array($this, [self::Arrs, self::Warzone], true);
    }
}
