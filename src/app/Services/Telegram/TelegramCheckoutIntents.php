<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirProviderAdapter;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class TelegramCheckoutIntents
{
    public function __construct(private TelegramBotClient $bot, private PakasirProviderAdapter $pakasir) {}

    public function sendPaymentInstructions(string $chatId, Order $order, ?Payment $payment): void
    {
        $settings = ManualPaymentSetting::current();
        $danaQrImageUrl = $settings?->dana_qr_image_url ?: config('services.telegram.dana_qr_image_url');
        $danaQrImagePath = $settings?->dana_qr_image_path;
        $danaAccountName = $settings?->dana_account_name ?: config('services.telegram.dana_account_name');
        $danaAccountNumber = $settings?->dana_account_number ?: config('services.telegram.dana_account_number');
        $adminContactUrl = $settings?->admin_contact_url ?: config('services.telegram.admin_contact_url');
        $adminUsername = $settings?->admin_username ?: config('services.telegram.admin_username');
        $adminTelegramChatId = $settings?->admin_telegram_chat_id;
        $instructions = $settings?->instructions;

        if (! $danaQrImageUrl && $danaQrImagePath) {
            /** @var FilesystemAdapter $publicDisk */
            $publicDisk = Storage::disk('public');
            $danaQrImageUrl = $publicDisk->url($danaQrImagePath);
        }

        $lines = [
            '✅ Checkout berhasil dibuat.',
            '',
            'Invoice: '.$order->invoice_number,
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
        ];

        if ($payment?->merchant_ref) {
            $lines[] = 'Kode Referensi: '.$payment->merchant_ref;
        }

        if ($order->expires_at) {
            $lines[] = 'Batas pembayaran: '.$this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = $instructions ?: 'Silakan bayar manual via DANA dengan nominal sesuai total di atas.';

        if ($danaAccountName || $danaAccountNumber) {
            $lines[] = '';
            $lines[] = 'Tujuan DANA:';
            if ($danaAccountName) {
                $lines[] = 'Nama: '.$danaAccountName;
            }
            if ($danaAccountNumber) {
                $lines[] = 'Nomor: '.$danaAccountNumber;
            }
        }

        if ($danaQrImageUrl) {
            $lines[] = '';
            $lines[] = 'QR DANA: '.$danaQrImageUrl;
        }

        $lines[] = '';
        $lines[] = 'Detail pembelian untuk admin:';
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity.' = Rp '.number_format($item->subtotal, 0, ',', '.');
        }

        $lines[] = '';
        $lines[] = 'Setelah transfer, kirim screenshot bukti pembayaran sebagai foto dengan caption /payment.';
        $lines[] = 'Bisa juga ketik /payment setelah mengirim bukti; admin akan cek manual dan produk dikirim setelah disetujui.';
        $lines[] = '';
        $lines[] = $this->adminPurchaseMessage($order);

        if ($adminContactUrl || $adminUsername || $adminTelegramChatId) {
            if ($adminContactUrl) {
                $lines[] = 'Kontak admin: '.$adminContactUrl;
            }
            if ($adminUsername) {
                $lines[] = 'Username admin: '.$adminUsername;
            }
            if ($adminTelegramChatId) {
                $lines[] = 'Chat ID admin: '.$adminTelegramChatId;
            }
        } else {
            $lines[] = 'Jika membutuhkan bantuan, silakan hubungi admin toko.';
        }

        $replyMarkup = [];
        if ($adminContactUrl) {
            $replyMarkup = ['inline_keyboard' => [[['text' => 'Chat Admin', 'url' => $adminContactUrl]]]];
        } elseif ($adminUsername) {
            $replyMarkup = ['inline_keyboard' => [[['text' => 'Chat Admin', 'url' => 'https://t.me/'.ltrim($adminUsername, '@')]]]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), $replyMarkup);
    }

    public function sendPakasirPaymentInstructions(string $chatId, Order $order, Payment $payment): void
    {
        $order->loadMissing('orderItems');
        $paymentUrl = $this->pakasir->hostedPaymentUrl($payment);

        $lines = [
            '🧾 Invoice: '.$order->invoice_number,
            '📦 Produk: '.$this->orderItemsSummary($order),
            '🔢 Jumlah: '.$order->orderItems->sum('quantity'),
            '💰 Total : Rp'.number_format($order->total_amount, 0, ',', '.').' ⚠️ wajib SAMA PERSIS',
        ];

        if ($order->expires_at) {
            $lines[] = '⏳ Order kadaluarsa pukul '.$this->formatDate($order->expires_at).'.';
        }

        $lines[] = '';
        $lines[] = '📲 Scan QRIS atau tap 💳 Bayar Melalui Website (Direct) untuk halaman pembayaran.';
        $lines[] = '✅ Pembayaran otomatis terverifikasi — produk langsung dikirim setelah pembayaran berhasil.';

        $this->bot->sendPhoto(
            $chatId,
            $this->qrPng($paymentUrl),
            'pakasir-'.$payment->merchant_ref.'.png',
            implode("\n", $lines),
            ['inline_keyboard' => [[['text' => '💳 Bayar Melalui Website (Direct)', 'url' => $paymentUrl]]]],
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
        $lines = [
            '🧾 Order manual baru masuk dashboard.',
            'Invoice: '.$order->invoice_number,
            'Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = '';
        $lines[] = 'Customer diminta kirim screenshot dengan caption /payment.';
        $lines[] = 'Kamu juga bisa approve lewat tombol yang muncul saat bukti pembayaran masuk.';

        $this->bot->sendMessage($adminTelegramChatId, implode("\n", $lines));
    }

    public function paymentMode(): string
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));

        return $mode === 'pakasir' ? 'pakasir' : 'manual';
    }

    public function adminPurchaseMessage(Order $order): string
    {
        $order->loadMissing('orderItems');
        $lines = [
            'Halo admin, saya ingin konfirmasi pembelian:',
            'Invoice: '.$order->invoice_number,
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = 'Total: Rp '.number_format($order->total_amount, 0, ',', '.');

        return implode("\n", $lines);
    }

    public function formatDate($date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }
}
