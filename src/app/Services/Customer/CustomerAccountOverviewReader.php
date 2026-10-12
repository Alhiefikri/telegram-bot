<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Support\Str;

final class CustomerAccountOverviewReader
{
    public function __construct(
        private readonly CustomerOwnedOrderQuery $orders,
        private readonly CustomerBalanceOverviewReader $balances,
    ) {}

    public function identity(Customer $customer): array
    {
        $name = trim((string) $customer->first_name);
        if ($name === '') {
            $name = trim((string) $customer->username);
        }
        if ($name === '') {
            $name = 'Pelanggan';
        }

        return [
            'name' => $name,
            'initial' => Str::upper(Str::substr($name, 0, 1)),
            'email' => $customer->email,
            'telegramUsername' => $customer->username,
            'joinedAt' => $customer->created_at?->locale('id')->translatedFormat('d F Y'),
            'emailVerified' => $customer->email_verified_at !== null,
        ];
    }

    public function forCustomer(Customer $customer, ?CustomerBalanceOverview $existingOverview = null): array
    {
        $overview = $existingOverview ?? $this->balances->forCustomer($customer);
        $idrBalance = $overview->storeBalance->isAvailable()
            ? $overview->storeBalance->balance()
            : null;

        return [
            'profile' => $this->identity($customer),
            'idrBalanceDisplay' => $idrBalance === null ? null : 'Rp '.preg_replace('/\B(?=(?:\d{3})+(?!\d))/', '.', (string) $idrBalance),
            'usdtBalanceDisplay' => $overview->usdtBalance->isAvailable()
                ? UsdtMicroAmount::formatUnits($overview->usdtBalance->balanceMicro()).' USDT'
                : null,
            'usdtFrozen' => $overview->usdtBalance->isAvailable() && $overview->usdtBalance->isFrozen(),
            'orderCounts' => $this->orders->counts($customer),
        ];
    }
}
