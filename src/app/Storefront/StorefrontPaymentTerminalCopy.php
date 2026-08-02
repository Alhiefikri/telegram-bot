<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderLifecycleProjection;

final class StorefrontPaymentTerminalCopy
{
    public function message(OrderLifecycleProjection $projection, ?Payment $payment): string
    {
        if ($projection->isPaid) {
            return 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.';
        }

        $isBinance = $payment?->method === 'BINANCE_PAY_ID';

        return match ($projection->terminalCause) {
            'expired' => $isBinance
                ? 'Invoice Binance sudah kedaluwarsa. Jangan kirim USDT untuk invoice ini; buat checkout Binance baru dari katalog.'
                : 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.',
            'cancelled' => $isBinance
                ? 'Order dibatalkan. Jangan kirim USDT untuk invoice ini; buat checkout Binance baru jika masih ingin membeli.'
                : 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.',
            'provider_failed' => $isBinance
                ? 'Verifikasi Binance gagal. Jangan kirim USDT untuk invoice ini; buat checkout baru atau hubungi admin.'
                : 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.',
            default => 'Pembayaran belum masuk. Jika sudah bayar, coba cek lagi beberapa saat lagi.',
        };
    }

    public function effectivePaymentStatus(Order $order, ?Payment $payment): string
    {
        return match ($order->order_status) {
            OrderStatus::Cancelled => 'cancelled',
            OrderStatus::Expired => 'expired',
            default => $payment?->status?->value ?? $order->payment_status?->value ?? '-',
        };
    }
}
