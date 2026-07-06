<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\SalesProofPost;

class SalesProofPostFormatter
{
    public function __construct(private readonly SalesProofPostNumbering $numbering) {}

    public function format(Order $order, ?int $successfulPostNumber = null): string
    {
        $order->loadMissing(['customer', 'orderItems']);
        $successfulPostNumber ??= $this->nextSuccessfulPostNumber($order);
        $primary = $order->orderItems->first()?->product_name ?? 'Produk digital';
        $otherCount = max(0, $order->orderItems->count() - 1);
        $other = $otherCount > 0 ? " + {$otherCount} produk lainnya" : '';

        return $this->formatMessage($this->maskBuyer($order), $primary.$other, (int) $order->orderItems->sum('quantity'), $successfulPostNumber);
    }

    public function formatFake(string $buyerMask, string $productName, int $quantity, int $successfulPostNumber): string
    {
        return $this->formatMessage($buyerMask, $productName, $quantity, $successfulPostNumber);
    }

    private function nextSuccessfulPostNumber(Order $order): int
    {
        return $order->salesProofPost?->status === SalesProofPost::STATUS_SENT
            ? $this->numbering->sentCount()
            : $this->numbering->nextNumber();
    }

    private function maskBuyer(Order $order): string
    {
        $name = (string) ($order->customer?->username ?: $order->customer?->first_name ?: 'Buyer');
        $name = ltrim(trim($name), '@');

        return mb_substr($name, 0, 2).'***';
    }

    private function formatMessage(string $buyerMask, string $productName, int $quantity, int $successfulPostNumber): string
    {
        return "🎉 PEMBELIAN SUKSES 🎉\n\n"
            .'👤 Pembeli: '.$buyerMask."\n"
            .'🛍️ Produk: '.$productName."\n"
            .'📦 Jumlah: '.$quantity."x\n"
            .'🏆 Order sukses: #'.$successfulPostNumber."\n\n"
            ."✨ Terima kasih telah berbelanja!\n"
            .'Mau order juga? Tap tombol di bawah 👇';
    }
}
