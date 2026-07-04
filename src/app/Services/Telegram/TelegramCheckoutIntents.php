<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Support\DogfoodTiming;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TelegramCheckoutIntents
{
    public function __construct(private TelegramBotClient $bot, private PakasirProviderAdapter $pakasir) {}

    public function sendPaymentInstructions(string $chatId, Order $order): void
    {
        $order->loadMissing('orderItems');
        $method = $this->manualPaymentOption();
        $supportUsername = ltrim((string) (config('services.telegram.admin_username') ?: 'Alhie02'), '@');
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');

        $lines = [
            '✅ Checkout berhasil dibuat',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '💰 Total: '.$formattedTotal,
            '💳 Bayar via: '.$method['label'],
            '',
            '📲 Scan QR manual di atas.',
            '',
            'Sebelum bayar, pastikan:',
            '• Nama QR: '.$method['name'],
            '• Nominal wajib sama persis: '.$formattedTotal,
        ];

        if ($order->expires_at) {
            $lines[] = '';
            $lines[] = '⏳ Batas bayar:';
            $lines[] = $this->formatDate($order->expires_at);
            $lines[] = 'Stok dipegang '.(int) config('orders.pending_payment_ttl_minutes', 5).' menit. Kalau belum bayar/kirim bukti, order otomatis expired dan stok balik.';
        }

        $lines[] = '';
        $lines[] = '📦 Pesanan: '.$this->orderItemsSummary($order);
        $lines[] = '';
        $lines[] = 'Setelah bayar, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:';
        $lines[] = '';
        $lines[] = '/payment';
        $lines[] = '';
        $lines[] = 'Kalau kesusahan, chat admin @'.$supportUsername.'.';

        $qrContents = DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => 'manual_qr_file_read',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
        ], function (): string {
            $qrPath = public_path('qr-lhie.jpg');
            if (! is_readable($qrPath)) {
                throw new \RuntimeException('Manual QR image is not readable: '.$qrPath);
            }

            $qrContents = file_get_contents($qrPath);
            if ($qrContents === false) {
                throw new \RuntimeException('Manual QR image could not be read: '.$qrPath);
            }

            return $qrContents;
        });

        DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => 'manual_customer_send_photo',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
            'qr_image_bytes' => strlen($qrContents),
        ], fn () => $this->bot->sendPhoto($chatId, $qrContents, 'qr-lhie.jpg', implode("\n", $lines)));
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
        $method = $this->manualPaymentOption();
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
        $lines[] = 'Tunggu bukti pembayaran dengan caption /payment.';
        $lines[] = 'Approve hanya jika pembayaran QR manual masuk, nama QR LHIE STORE benar, dan nominal sama persis.';

        DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => 'manual_admin_send_message',
            'payment_mode' => 'manual',
            'order_correlation_id' => DogfoodTiming::correlation($order->id),
        ], fn () => $this->bot->sendMessage($adminTelegramChatId, implode("\n", $lines)));
    }

    public function paymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    public function manualPaymentMethod(): string
    {
        return 'QR_MANUAL';
    }

    public function manualPaymentOption(): array
    {
        return [
            'label' => 'QR Manual',
            'name' => 'LHIE STORE',
        ];
    }

    public function formatDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }
}
