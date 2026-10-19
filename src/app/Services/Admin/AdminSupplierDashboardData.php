<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\SupplierProvider;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierMoney;
use Carbon\CarbonImmutable;

final class AdminSupplierDashboardData
{
    public function forTab(array $filters = []): array
    {
        $connections = Supplier::query()->whereIn('provider', SupplierProvider::cases())->orderBy('id')->paginate(15, ['*'], 'suppliers_page');
        // One aggregate row per connection so the connection form can show a real
        // derived SKU cost instead of an illustrative number.
        $sampleUnits = SupplierCatalogItem::query()
            ->whereIn('supplier_id', $connections->getCollection()->pluck('id'))
            ->whereNotNull('unit_price_micros')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, MAX(unit_price_micros) AS unit_price_micros')
            ->pluck('unit_price_micros', 'supplier_id');
        // Hold on an unpaid order is capacity reserved at checkout: nothing was
        // purchased yet and the hold clears on its own at expiry. Only the
        // remainder belongs to an unproven purchase, so the two are reported apart.
        // Mirrors SupplierFulfillment::isUnpaidReservation() for the whole page.
        $pendingPaymentHolds = SupplierFulfillment::query()
            ->join('orders', 'orders.id', '=', 'supplier_fulfillments.order_id')
            ->whereIn('supplier_fulfillments.reserved_supplier_id', $connections->getCollection()->pluck('id'))
            ->where('supplier_fulfillments.reserved_quantity', '>', 0)
            ->whereNull('supplier_fulfillments.current_purchase_id')
            ->where('orders.order_status', OrderStatus::PendingPayment->value)
            ->get(['supplier_fulfillments.reserved_supplier_id', 'supplier_fulfillments.reserved_cost_micros', 'orders.expires_at'])
            ->groupBy('reserved_supplier_id');
        $connections->setCollection($connections->getCollection()->map(function (Supplier $supplier) use ($sampleUnits, $pendingPaymentHolds): array {
            $available = $supplier->balance_micros === null ? null : max(0, (int) $supplier->balance_micros - (int) $supplier->reserved_balance_micros);
            $sampleUnit = $sampleUnits->get($supplier->id);
            $pendingPayment = $pendingPaymentHolds->get($supplier->id) ?? collect();
            $pendingPaymentHold = (int) $pendingPayment->sum('reserved_cost_micros');
            $pendingPaymentReleaseAt = $pendingPayment->max('expires_at');

            return [
                ...$supplier->only(['id', 'name', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'version', 'low_balance_threshold_micros']),
                'sample_unit' => $sampleUnit === null ? null : rtrim(rtrim(SupplierMoney::decimal((int) $sampleUnit), '0'), '.'),
                'balance' => $supplier->balance_micros === null ? 'Belum diketahui' : SupplierMoney::balanceDecimal((int) $supplier->balance_micros),
                'held_balance' => SupplierMoney::decimal((int) $supplier->reserved_balance_micros),
                'available_balance' => $available === null ? 'Belum diketahui' : SupplierMoney::decimal($available),
                'low_balance' => $available !== null && $available <= (int) $supplier->low_balance_threshold_micros,
                'synced_at' => $supplier->synced_at?->toIso8601String(),
                'expires_at' => $supplier->sync_expires_at?->toIso8601String(),
                'balance_observed_at' => $supplier->balance_observed_at?->toIso8601String(),
                'balance_uncertain' => $supplier->balance_micros === null
                    || $supplier->balance_observed_at?->lte(now()->subSeconds(SupplierCatalogSync::FRESHNESS_SECONDS)) !== false,
                'has_unresolved_hold' => max(0, (int) $supplier->reserved_balance_micros - $pendingPaymentHold) > 0,
                'has_pending_payment_hold' => $pendingPayment->isNotEmpty(),
                'pending_payment_hold' => SupplierMoney::decimal($pendingPaymentHold),
                'pending_payment_hold_count' => $pendingPayment->count(),
                'pending_payment_hold_release_at' => $pendingPaymentReleaseAt === null
                    ? null
                    : CarbonImmutable::parse($pendingPaymentReleaseAt)->timezone(config('app.timezone'))->toIso8601String(),
                'sync_error' => $supplier->last_error !== null ? $this->syncFailureMessage($supplier) : null,
            ];
        }));

        $search = trim((string) ($filters['supplier_search'] ?? ''));
        $status = (string) ($filters['supplier_status'] ?? 'all');
        $source = (string) ($filters['supplier_source'] ?? 'all');
        $productsQuery = Product::query()
            ->select(['id', 'name', 'price_amount', 'is_active'])
            ->with([
                'supplierPricePolicy',
                'supplierSources' => fn ($sources) => $sources
                    ->select(['id', 'product_id', 'supplier_catalog_item_id', 'priority', 'is_active'])
                    ->with([
                        'catalogItem' => fn ($items) => $items
                            ->select(['id', 'supplier_id', 'name', 'external_id', 'currency', 'unit_price_micros', 'orderable'])
                            ->with('supplier:id,name,provider'),
                    ]),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($match) use ($search): void {
                    $match->where('name', 'like', '%'.$search.'%');
                    if (ctype_digit($search)) {
                        $match->orWhere('id', (int) $search);
                    }
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($source === 'mapped', fn ($query) => $query->whereHas('supplierSources'))
            ->when($source === 'unmapped', fn ($query) => $query->whereDoesntHave('supplierSources'))
            ->when(ctype_digit($source), fn ($query) => $query->whereHas('supplierSources.catalogItem', fn ($items) => $items->where('supplier_id', (int) $source)))
            ->orderBy('id');

        $productFilters = array_filter([
            'tab' => 'suppliers',
            'supplier_search' => $search !== '' ? $search : null,
            'supplier_status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
            'supplier_source' => in_array($source, ['mapped', 'unmapped'], true) || ctype_digit($source) ? $source : null,
        ], static fn ($value): bool => $value !== null);
        $products = $productsQuery->paginate(12, ['id', 'name', 'price_amount', 'is_active'], 'supplier_products_page')
            ->appends($productFilters)
            ->fragment('admin-supplier-products');
        $sourceOptions = Supplier::query()
            ->whereIn('provider', SupplierProvider::cases())
            ->whereHas('catalogItems.sources')
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'connections' => $connections->appends(['tab' => 'suppliers']),
            'products' => $products,
            'productFilters' => [
                'search' => $search,
                'status' => in_array($status, ['active', 'inactive'], true) ? $status : 'all',
                'source' => in_array($source, ['mapped', 'unmapped'], true) || ctype_digit($source) ? $source : 'all',
                'sourceOptions' => $sourceOptions,
            ],
        ];
    }

    public function syncFailureMessage(Supplier $supplier): string
    {
        if (! $supplier->is_active) {
            return 'Aktifkan supplier terlebih dahulu, lalu sinkronkan katalog.';
        }
        if ($supplier->sync_token !== null && $supplier->sync_lease_expires_at?->isFuture()) {
            return 'Sinkron katalog masih berjalan. Tunggu sampai selesai sebelum mencoba lagi.';
        }

        return match ($supplier->last_error) {
            'authentication', 'invalid_key' => 'API key ditolak oleh supplier. Periksa key dan pastikan akses API aktif.',
            'credential_unreadable' => 'API key tersimpan tidak dapat dibaca (misalnya APP_KEY aplikasi berubah atau database berasal dari environment lain). Masukkan ulang API key lewat Edit koneksi.',
            'rate_limited' => 'Batas permintaan ke supplier sedang tercapai. Tunggu beberapa menit sebelum sinkron lagi.',
            'transport', 'snapshot_timeout' => 'Supplier belum merespons tepat waktu. Katalog sebelumnya tetap disimpan. Coba lagi nanti.',
            'invalid_payload' => 'Format data supplier belum dapat dibaca. Katalog sebelumnya tetap disimpan.',
            'catalog_limit' => 'Katalog supplier melebihi batas pembacaan sekali sinkron. Data belum diperbarui.',
            'currency_mismatch' => 'Mata uang dari supplier tidak sesuai dengan koneksi. Periksa mata uang akun supplier.',
            'duplicate_account' => 'Akun supplier ini sudah terhubung pada koneksi lain.',
            'account_identity_mismatch' => 'API key mengarah ke akun yang berbeda. Periksa koneksi supplier.',
            'upstream', 'redirect', 'request_failed' => 'Layanan supplier sedang bermasalah. Katalog sebelumnya tetap disimpan. Coba lagi nanti.',
            default => 'Sinkron belum diterapkan. Muat ulang halaman dan periksa pengaturan supplier sebelum mencoba lagi.',
        };
    }
}
