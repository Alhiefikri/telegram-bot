<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Support\DogfoodTiming;
use Carbon\CarbonInterface;

class TelegramPaymentInstructionRenderer
{
    public function __construct(private TelegramQrCodeRenderer $qrCodes) {}

    /**
     * @param array{label: string, name: string} $paymentOption
     */
    public function manual(Order $order, array $paymentOption, string $supportUsername): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');

        $lines = [
            '✅ Checkout berhasil dibuat',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '💰 Total: '.$formattedTotal,
            '💳 Bayar via: '.$paymentOption['label'],
            '',
            '📲 Scan QR manual di atas.',
            '',
            'Sebelum bayar, pastikan:',
            '• Nama QR: '.$paymentOption['name'],
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

        return new TelegramPaymentInstruction(
            photoContents: $this->manualQrPhotoContents($order),
            filename: 'qr-lhie.jpg',
            captionLines: $lines,
        );
    }

    public function hostedPakasir(Order $order, Payment $payment, string $paymentUrl, bool $fallback = false): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $lines = [];

        if ($fallback) {
            $lines = [
                '⚠️ Fallback checkout Pakasir',
                'QRIS langsung belum bisa dibuat otomatis. Order tetap pending dan stok tetap dipegang.',
                'Gunakan QR/link website Pakasir di bawah untuk lanjut bayar.',
                '',
            ];
        }

        $lines = array_merge($lines, $this->pakasirOrderHeaderLines($order), [
            '💰 Total: '.$this->formatRupiah($order->total_amount),
            '',
            '⚠️ Bayar dengan nominal yang SAMA PERSIS ya.',
        ]);

        if ($order->expires_at) {
            $lines[] = '⏳ Order aktif sampai: '.$this->formatDate($order->expires_at);
        }

        $lines[] = '';
        $lines[] = '📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.';
        $lines[] = '';
        $lines[] = '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';

        return new TelegramPaymentInstruction(
            photoContents: $this->qrCodes->png($paymentUrl),
            filename: 'pakasir-'.$payment->merchant_ref.'.png',
            captionLines: $lines,
            replyMarkup: $this->websitePaymentButton($paymentUrl),
        );
    }

    public function nativePakasir(Order $order, Payment $payment, PakasirQrisTransaction $transaction, string $paymentUrl): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $payableAmount = $transaction->totalPayment() ?? $payment->amount;
        $expiry = $this->earliestDate($transaction->expiresAt(), $payment->provider_expires_at, $payment->expired_at, $order->expires_at);

        $lines = array_merge($this->pakasirOrderHeaderLines($order), [
            '💰 Total produk: '.$this->formatRupiah($order->total_amount),
            '🏷️ Biaya QRIS: '.($transaction->fee() === null ? 'Belum dikirim Pakasir' : $this->formatRupiah($transaction->fee())),
            '💳 Total bayar QRIS: '.$this->formatRupiah($payableAmount),
        ]);

        if ($expiry) {
            $lines[] = '⏳ Batas bayar QRIS: '.$this->formatDate($expiry);
        }

        $lines[] = '';
        $lines[] = '📲 Scan QRIS di atas langsung dari Telegram. Tidak perlu buka browser kalau QR bisa discan.';
        $lines[] = '🌐 Kalau QR tidak bisa discan, tap tombol Bayar via Website sebagai fallback.';
        $lines[] = '';
        $lines[] = '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';

        return new TelegramPaymentInstruction(
            photoContents: $this->qrCodes->png($transaction->paymentNumber()),
            filename: 'pakasir-qris-'.$payment->merchant_ref.'.png',
            captionLines: $lines,
            replyMarkup: $this->websitePaymentButton($paymentUrl),
        );
    }

    /**
     * @return list<string>
     */
    private function pakasirOrderHeaderLines(Order $order): array
    {
        return [
            '🧾 Invoice: '.$order->invoice_number,
            '━━━━━━━━━━━━━━',
            '📦 Produk: '.$this->orderItemsSummary($order),
            '🔢 Jumlah: '.$order->orderItems->sum('quantity'),
        ];
    }

    private function manualQrPhotoContents(Order $order): string
    {
        return DogfoodTiming::time('dogfood.telegram.checkout_step', [
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
    }

    private function earliestDate(?CarbonInterface ...$dates): ?CarbonInterface
    {
        $earliest = null;

        foreach ($dates as $date) {
            if ($date === null) {
                continue;
            }

            if ($earliest === null || $date->lt($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    private function formatRupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private function orderItemsSummary(Order $order): string
    {
        return $order->orderItems
            ->map(fn ($item) => $item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : ''))
            ->join(', ');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, url: string}>>}
     */
    private function websitePaymentButton(string $paymentUrl): array
    {
        return ['inline_keyboard' => [[['text' => '💳 Bayar via Website', 'url' => $paymentUrl]]]];
    }

    private function formatDate(CarbonInterface $date): string
    {
        return $date->timezone(config('app.timezone'))->format('d M Y H:i').' WIB';
    }
}
