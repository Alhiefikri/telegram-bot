<?php

namespace App\Storefront;

use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;

final class StorefrontCheckoutMaintenanceCopy
{
    public const DEFAULT_NOTICE = 'Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.';

    public static function notice(CheckoutMaintenanceState $state): string
    {
        if (! $state->active) {
            return '';
        }

        return $state->publicNote === null
            ? self::DEFAULT_NOTICE
            : self::DEFAULT_NOTICE."\n\nCatatan admin:\n{$state->publicNote}";
    }
}
