<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TelegramOrderIntents
{
    public function __construct(private TelegramBotClient $bot, private PakasirProviderAdapter $pakasir, private PakasirCallbackProcessor $pakasirProcessor, private PakasirProviderReadiness $pakasirReadiness, private TelegramLocale $locale) {}

    public function sendOrderHistory(Customer $customer, string $chatId): void
    {
        $orders = $customer->orders()->latest()->limit(5)->get();
        if ($orders->isEmpty()) {
            $this->bot->sendMessage($chatId, $this->text($customer, "📦 Riwayat masih kosong\n━━━━━━━━━━━━━━\nKamu belum punya pesanan.\n\nYuk lihat produk dulu, siapa tahu ada yang cocok.", "📦 No order history\n━━━━━━━━━━━━━━\nYou do not have any orders yet.\n\nBrowse products to get started."), ['inline_keyboard' => [[['text' => $this->text($customer, '🛍️ Lihat Produk', '🛍️ Browse Products'), 'callback_data' => 'katalog']]]]);

            return;
        }

        $lines = [$this->text($customer, '📦 Riwayat Pesanan Terakhir', '📦 Recent Order History'), '━━━━━━━━━━━━━━', $this->text($customer, 'Tekan Detail untuk lihat isi pesanan.', 'Tap Details to view an order.')];
        $keyboard = [];
        foreach ($orders as $i => $order) {
            $lines[] = ($i + 1).'. '.$order->invoice_number.' · '.$order->order_status->value.' / '.$order->payment_status->value.' · Rp '.number_format($order->total_amount, 0, ',', '.').' · '.$this->formatShortDate($order->created_at);
            $keyboard[] = [['text' => $this->text($customer, '🧾 Detail ', '🧾 Details ').$order->invoice_number, 'callback_data' => 'order:'.$order->invoice_number]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendOrderDetail(Customer $customer, string $chatId, string $invoice): void
    {
        $order = $customer->orders()->where('invoice_number', $invoice)->with(['orderItems.stockItems', 'orderItems.product', 'payments' => fn ($query) => $query->latest()])->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, $this->text($customer, "Pesanan tidak ditemukan.\n\nCoba cek nomor invoice-nya lagi, atau lihat daftar pesanan kamu di /riwayat.", "Order not found.\n\nCheck the invoice number or view your order history with /history."));

            return;
        }

        $payment = $order->payments->first();
        $refreshNotice = $this->refreshPendingPakasirPayment($customer, $order, $payment);
        if ($refreshNotice !== null) {
            $order = $order->fresh(['orderItems.stockItems', 'orderItems.product', 'payments' => fn ($query) => $query->latest()]);
            $payment = $order->payments->first();
        }
        $paymentStatus = $payment?->status ?? $order->payment_status;
        $lines = [
            $this->text($customer, '🧾 Detail Pesanan', '🧾 Order Details'),
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            $this->text($customer, 'Status: ', 'Status: ').$order->order_status->value.' / '.$paymentStatus->value,
            $this->text($customer, 'Dibuat: ', 'Created: ').$this->formatDate($order->created_at),
            '',
            $this->text($customer, 'Item:', 'Items:'),
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Total: Rp '.number_format($order->total_amount, 0, ',', '.');
        if ($payment) {
            $lines[] = $this->text($customer, 'Pembayaran: ', 'Payment: ').$payment->payment_method_label.' ('.$payment->status->value.')';
            if ($payment->paid_at) {
                $lines[] = $this->text($customer, 'Dibayar: ', 'Paid: ').$this->formatDate($payment->paid_at);
            }
        }
        if (($order->order_status === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->text($customer, 'Batas pembayaran: ', 'Payment deadline: ').$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        if (in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true) && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->text($customer, 'Berakhir: ', 'Expired: ').$this->formatDate($payment?->expired_at ?? $order->expires_at);
        }
        if ($refreshNotice !== null) {
            $lines[] = '';
            $lines[] = $refreshNotice;
        }
        $accountLines = $this->accountSecretLines($order, $paymentStatus);
        if ($accountLines !== []) {
            $lines[] = '';
            $lines[] = $this->text($customer, '🔐 Data akun:', '🔐 Account details:');
            array_push($lines, ...$accountLines);
        }
        $lines[] = '';
        $lines[] = $this->orderInstruction($customer, $order, $payment);

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    public function orderInstruction(Customer $customer, Order $order, ?Payment $payment): string
    {
        $orderStatus = $order->order_status;
        $paymentStatus = $payment?->status ?? $order->payment_status;
        if ($orderStatus === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) {
            return $this->text($customer, 'Order masih menunggu pembayaran. Selesaikan pembayaran sebelum batas waktu ya.', 'This order is awaiting payment. Complete payment before the deadline.');
        }
        if (in_array($orderStatus, [OrderStatus::Processing, OrderStatus::Completed], true) || $paymentStatus === PaymentStatus::Paid) {
            return $this->text($customer, 'Pembayaran sudah diterima. Pesanan sedang atau sudah selesai diproses.', 'Payment has been received. Your order is being processed or is complete.');
        }
        if (in_array($orderStatus, [OrderStatus::Expired, OrderStatus::Cancelled], true) || in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true)) {
            return $this->text($customer, 'Order ini sudah tidak aktif. Kamu bisa checkout ulang lewat /produk.', 'This order is no longer active. You can check out again via /products.');
        }

        return $this->text($customer, 'Kalau butuh bantuan, hubungi admin toko lewat /support.', 'Need help? Contact store support via /support.');
    }

    private function refreshPendingPakasirPayment(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $payment
            || $payment->provider !== 'pakasir'
            || $payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || blank($payment->merchant_ref)
            || ! $this->pakasirReadiness->verification()->isReady()) {
            return null;
        }

        $key = 'pakasir:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.pakasir.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->text($customer, "Cek status pembayaran dibatasi sementara. Coba lagi dalam {$seconds} detik.", "Payment-status checks are temporarily limited. Try again in {$seconds} seconds.");
        }

        RateLimiter::hit($key, 60);

        try {
            $event = $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasir->transactionDetail($payment));
        } catch (Throwable $e) {
            report($e);

            return $this->text($customer, 'Status pembayaran belum bisa dicek otomatis sekarang. Coba lagi nanti.', 'Payment status cannot be checked automatically right now. Please try again later.');
        }

        if ($event->processed_at !== null) {
            return $this->text($customer, 'Status pembayaran dicek ulang dan sudah terverifikasi.', 'Payment status was checked again and verified.');
        }

        return $this->text($customer, 'Status pembayaran sudah dicek. Belum ada pembayaran selesai.', 'Payment status was checked. No completed payment was found.');
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
            $deliveryNote = trim((string) ($item->delivery_note_snapshotted ? $item->delivery_note : $item->product?->delivery_note));
            if ($deliveryNote !== '') {
                $lines[] = '📌 Catatan pengiriman:';
                $lines[] = $deliveryNote;
            }
        }

        return $lines;
    }

    public function formatDate(CarbonInterface $date): string
    {
        $timezone = (string) config('app.timezone');

        return $date->copy()->timezone($timezone)->format('d M Y H:i').' '.$this->timezoneLabel($timezone);
    }

    public function formatShortDate(CarbonInterface $date): string
    {
        return $date->copy()->timezone((string) config('app.timezone'))->format('d M Y');
    }

    private function timezoneLabel(string $timezone): string
    {
        return match ($timezone) {
            'Asia/Jakarta' => 'WIB',
            'Asia/Makassar', 'Asia/Ujung_Pandang' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => $timezone,
        };
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
