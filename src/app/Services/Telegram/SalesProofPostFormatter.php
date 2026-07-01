<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\SalesProofPost;

class SalesProofPostFormatter
{
    public function format(Order $order, ?int $successfulPostNumber = null): string
    {
        $order->loadMissing(['customer', 'orderItems']);
        $successfulPostNumber ??= $this->nextSuccessfulPostNumber($order);
        $primary = $order->orderItems->first()?->product_name ?? 'Produk digital';
        $otherCount = max(0, $order->orderItems->count() - 1);
        $other = $otherCount > 0 ? " + {$otherCount} produk lainnya" : '';

        return "✅ Pesanan berhasil diproses\n\n"
            .'Buyer: '.$this->maskBuyer($order)."\n"
            .'Produk: '.$primary.$other."\n\n"
            .'Total diproses: #'.$successfulPostNumber."\n\n"
            .'Mau produk digital juga? Klik tombol di bawah 👇';
    }

    private function nextSuccessfulPostNumber(Order $order): int
    {
        $sentCount = SalesProofPost::query()->where('status', SalesProofPost::STATUS_SENT)->count();

        return $order->salesProofPost?->status === SalesProofPost::STATUS_SENT ? $sentCount : $sentCount + 1;
    }

    private function maskBuyer(Order $order): string
    {
        $name = (string) ($order->customer?->username ?: $order->customer?->first_name ?: 'Buyer');
        $name = ltrim(trim($name), '@');

        return mb_substr($name, 0, 2).'***';
    }
}
