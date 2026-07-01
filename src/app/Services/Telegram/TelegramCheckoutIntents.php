<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirProviderAdapter;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TelegramCheckoutIntents
{
    public function __construct(private TelegramBotClient $bot, private PakasirProviderAdapter $pakasir) {}

    public function sendPaymentInstructions(string $chatId, Order $order, ?Payment $payment): void
    {
        $method = $this->manualPaymentOption($payment?->method);
        $supportUsername = ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@');

        $lines = [
            '✅ Checkout berhasil dibuat',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '💰 Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '💳 Bayar via: '.$method['label'],
            '',
            'Tujuan transfer:',
            $method['number'],
            'A/N '.$method['name'],
        ];

        if ($order->expires_at) {
            $lines[] = '';
            $lines[] = '⏳ Batas bayar:';
            $lines[] = $this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = 'Pesanan kamu:';
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x'.$item->quantity;
        }

        $lines[] = '';
        $lines[] = 'Setelah transfer, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:';
        $lines[] = '';
        $lines[] = '/payment';
        $lines[] = '';
        $lines[] = 'Kalau butuh bantuan, hubungi @'.$supportUsername.'.';

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    public function sendPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment): void
    {
        $order->loadMissing('orderItems');
        $paymentUrl = $this->pakasir->hostedPaymentUrl($payment);

        $lines = [
            '🧾 Invoice: '.$order->invoice_number,
            '━━━━━━━━━━━━━━',
            '📦 Produk: '.$this->orderItemsSummary($order),
            '🔢 Jumlah: '.$order->orderItems->sum('quantity'),
            '💰 Total: Rp'.number_format($order->total_amount, 0, ',', '.'),
            '',
            '⚠️ Bayar dengan nominal yang SAMA PERSIS ya.',
        ];

        if ($order->expires_at) {
            $lines[] = '⏳ Order aktif sampai: '.$this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = '📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.';
        $lines[] = '';
        $lines[] = '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';

        $this->bot->sendPhoto(
            $chatId,
            $this->qrPng($paymentUrl),
            'pakasir-'.$payment->merchant_ref.'.png',
            implode("\n", $lines),
            ['inline_keyboard' => [[['text' => '💳 Bayar via Website', 'url' => $paymentUrl]]]],
        );
    }

    public function orderItemsSummary(Order $order): string
    {
        return $order->orderItems
            ->map(fn ($item) => $item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : ''))
            ->join(', ');
    }

    public function qrPng(string $content): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'scale' => 8,
        ]);

        return (new QRCode($options))->render($content);
    }

    public function notifyAdminAboutManualOrder(Order $order): void
    {
        $settings = ManualPaymentSetting::current();
        $adminTelegramChatId = $settings?->admin_telegram_chat_id ?: config('services.telegram.admin_telegram_chat_id');
        if (! $adminTelegramChatId) {
            return;
        }

        $order->loadMissing(['customer', 'orderItems']);
        $payment = $order->payments()->latest()->first();
        $method = $this->manualPaymentOption($payment?->method);
        $lines = [
            '🧾 Checkout manual baru',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            'Metode: '.$method['label'],
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = '';
        $lines[] = 'Tunggu bukti pembayaran dengan caption /payment.';
        $lines[] = 'Setelah bukti masuk, approve dari tombol di foto bukti.';

        $this->bot->sendMessage($adminTelegramChatId, implode("\n", $lines));
    }

    public function paymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    public function manualPaymentMethod(?string $method): string
    {
        return match ($method) {
            'gopay' => 'GOPAY',
            'shopepay' => 'SHOPEEPAY',
            default => 'DANA',
        };
    }

    public function manualPaymentOption(?string $method): array
    {
        $label = match ($method) {
            'GOPAY' => 'GOPAY',
            'SHOPEEPAY' => 'SHOPEEPAY',
            default => 'DANA',
        };

        return [
            'label' => $label,
            'number' => '082190715067',
            'name' => 'MOH. ALIF FIKRI',
        ];
    }

    public function formatDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }
}
