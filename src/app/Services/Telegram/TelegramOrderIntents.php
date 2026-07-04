<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TelegramOrderIntents
{
    public function __construct(private TelegramBotClient $bot, private PakasirProviderAdapter $pakasir, private PakasirCallbackProcessor $pakasirProcessor) {}

    public function sendOrderHistory(Customer $customer, string $chatId): void
    {
        $orders = $customer->orders()->latest()->limit(5)->get();
        if ($orders->isEmpty()) {
            $this->bot->sendMessage($chatId, "📦 Riwayat masih kosong\n━━━━━━━━━━━━━━\nKamu belum punya pesanan.\n\nYuk lihat produk dulu, siapa tahu ada yang cocok.", ['inline_keyboard' => [[['text' => '🛍️ Lihat Produk', 'callback_data' => 'katalog']]]]);

            return;
        }

        $lines = ['📦 Riwayat Pesanan Terakhir', '━━━━━━━━━━━━━━', 'Tekan Detail untuk lihat isi pesanan.'];
        $keyboard = [];
        foreach ($orders as $i => $order) {
            $lines[] = ($i + 1).'. '.$order->invoice_number.' · '.$order->order_status->value.' / '.$order->payment_status->value.' · Rp '.number_format($order->total_amount, 0, ',', '.').' · '.$this->formatShortDate($order->created_at);
            $keyboard[] = [['text' => '🧾 Detail '.$order->invoice_number, 'callback_data' => 'order:'.$order->invoice_number]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendOrderDetail(Customer $customer, string $chatId, string $invoice): void
    {
        $order = $customer->orders()->where('invoice_number', $invoice)->with(['orderItems.stockItems', 'payments' => fn ($query) => $query->latest()])->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, "Pesanan tidak ditemukan.\n\nCoba cek nomor invoice-nya lagi, atau lihat daftar pesanan kamu di /riwayat.");

            return;
        }

        $payment = $order->payments->first();
        $refreshNotice = $this->refreshPendingPakasirPayment($customer, $order, $payment);
        if ($refreshNotice !== null) {
            $order = $order->fresh(['orderItems.stockItems', 'payments' => fn ($query) => $query->latest()]);
            $payment = $order->payments->first();
        }
        $paymentStatus = $payment?->status ?? $order->payment_status;
        $lines = [
            '🧾 Detail Pesanan',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Status: '.$order->order_status->value.' / '.$paymentStatus->value,
            'Dibuat: '.$this->formatDate($order->created_at),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Total: Rp '.number_format($order->total_amount, 0, ',', '.');
        if ($payment) {
            $lines[] = 'Pembayaran: '.$payment->method.' ('.$payment->status->value.')';
            if ($payment->paid_at) {
                $lines[] = 'Dibayar: '.$this->formatDate($payment->paid_at);
            }
        }
        if (($order->order_status === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = 'Batas pembayaran: '.$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        if (in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = 'Berakhir: '.$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        if ($refreshNotice !== null) {
            $lines[] = '';
            $lines[] = $refreshNotice;
        }
        $accountLines = $this->accountSecretLines($order, $paymentStatus);
        if ($accountLines !== []) {
            $lines[] = '';
            $lines[] = '🔐 Data akun:';
            array_push($lines, ...$accountLines);
        }
        $lines[] = '';
        $lines[] = $this->orderInstruction($order, $payment);

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    public function orderInstruction(Order $order, ?Payment $payment): string
    {
        $orderStatus = $order->order_status;
        $paymentStatus = $payment?->status ?? $order->payment_status;
        if ($orderStatus === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) {
            return 'Order masih menunggu pembayaran. Selesaikan pembayaran sebelum batas waktu ya.';
        }
        if (in_array($orderStatus, [OrderStatus::Processing, OrderStatus::Completed], true) || $paymentStatus === PaymentStatus::Paid) {
            return 'Pembayaran sudah diterima. Pesanan sedang atau sudah selesai diproses.';
        }
        if (in_array($orderStatus, [OrderStatus::Expired, OrderStatus::Cancelled], true) || in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true)) {
            return 'Order ini sudah tidak aktif. Kamu bisa checkout ulang lewat /produk.';
        }

        return 'Kalau butuh bantuan, hubungi admin toko lewat /support.';
    }

    private function refreshPendingPakasirPayment(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $payment
            || $payment->provider !== 'pakasir'
            || $payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || blank($payment->merchant_ref)
            || ! filled(config('services.pakasir.slug'))
            || ! filled(config('services.pakasir.api_key'))) {
            return null;
        }

        $key = 'pakasir:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.pakasir.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return "Cek status pembayaran dibatasi sementara. Coba lagi dalam {$seconds} detik.";
        }

        RateLimiter::hit($key, 60);

        try {
            $event = $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasir->transactionDetail($payment));
        } catch (Throwable $e) {
            report($e);

            return 'Status pembayaran belum bisa dicek ke Pakasir sekarang. Coba lagi nanti.';
        }

        if ($event->processed_at !== null) {
            return 'Status pembayaran dicek ulang ke Pakasir dan sudah terverifikasi.';
        }

        return 'Status pembayaran sudah dicek ke Pakasir. Belum ada pembayaran selesai.';
    }

    private function accountSecretLines(Order $order, PaymentStatus $paymentStatus): array
    {
        if ($paymentStatus !== PaymentStatus::Paid || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            return [];
        }

        $lines = [];
        foreach ($order->orderItems as $item) {
            if ($item->stockItems->isEmpty()) {
                continue;
            }

            $lines[] = $item->product_name;
            foreach ($item->stockItems->values() as $index => $stockItem) {
                $lines[] = ($index + 1).'. '.$stockItem->secret;
            }
        }

        return $lines;
    }

    public function formatDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }

    public function formatShortDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y');
    }
}
