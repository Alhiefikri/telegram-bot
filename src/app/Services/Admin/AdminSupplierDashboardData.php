<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierMoney;

final class AdminSupplierDashboardData
{
    public function forTab(): array
    {
        $connections = Supplier::query()->orderBy('id')->paginate(15, ['*'], 'suppliers_page');
        $connections->setCollection($connections->getCollection()->map(function (Supplier $supplier): array {
            $available = $supplier->balance_micros === null ? null : max(0, (int) $supplier->balance_micros - (int) $supplier->reserved_balance_micros);
            return [
                ...$supplier->only(['id', 'name', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'version', 'low_balance_threshold_micros']),
                'balance' => $supplier->balance_micros === null ? 'Belum diketahui' : SupplierMoney::decimal((int) $supplier->balance_micros),
                'held_balance' => SupplierMoney::decimal((int) $supplier->reserved_balance_micros),
                'available_balance' => $available === null ? 'Belum diketahui' : SupplierMoney::decimal($available),
                'low_balance' => $available !== null && $available <= (int) $supplier->low_balance_threshold_micros,
                'synced_at' => $supplier->synced_at?->toIso8601String(),
                'expires_at' => $supplier->sync_expires_at?->toIso8601String(),
            ];
        }));

        return ['connections' => $connections->appends(['tab' => 'suppliers']), 'products' => Product::query()->orderBy('id')->paginate(25, ['id', 'name', 'price_amount', 'is_active'], 'supplier_products_page')->appends(['tab' => 'suppliers'])];
    }
}
