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

        $notice = StorefrontLocale::text('checkout_maintenance_notice');

        return $state->publicNote === null
            ? $notice
            : $notice."\n\n".StorefrontLocale::text('admin_note')."\n".$state->publicNote;
    }
}
