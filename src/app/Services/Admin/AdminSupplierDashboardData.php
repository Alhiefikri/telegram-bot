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
                'balance' => $supplier->balance_micros === null ? 'Belum diketahui' : SupplierMoney::balanceDecimal((int) $supplier->balance_micros),
                'held_balance' => SupplierMoney::decimal((int) $supplier->reserved_balance_micros),
                'available_balance' => $available === null ? 'Belum diketahui' : SupplierMoney::decimal($available),
                'low_balance' => $available !== null && $available <= (int) $supplier->low_balance_threshold_micros,
                'synced_at' => $supplier->synced_at?->toIso8601String(),
                'expires_at' => $supplier->sync_expires_at?->toIso8601String(),
                'sync_error' => $supplier->last_error !== null ? $this->syncFailureMessage($supplier) : null,
                'webhook_url' => $supplier->provider === 'buymail' ? route('buymail.webhook', $supplier->id) : null,
                'webhook_configured' => $supplier->getRawOriginal('webhook_secret') !== null,
                'refunds_disabled' => $supplier->provider === 'buymail' && ($supplier->account_metadata['refunds_enabled'] ?? true) === false,
                'purchase_paused_until' => $supplier->purchase_paused_until?->isFuture() ? $supplier->purchase_paused_until->timezone(config('app.timezone'))->format('Y-m-d H:i T') : null,
            ];
        }));

        return ['connections' => $connections->appends(['tab' => 'suppliers']), 'products' => Product::query()->orderBy('id')->paginate(25, ['id', 'name', 'price_amount', 'is_active'], 'supplier_products_page')->appends(['tab' => 'suppliers'])];
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
