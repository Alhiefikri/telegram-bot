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
     * @param  array{label: string, name: string}  $paymentOption
     */
    public function manual(Order $order, array $paymentOption, string $supportUsername, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');

        $lines = $locale === 'en' ? [
            '✅ Checkout created', '━━━━━━━━━━━━━━', '🧾 Invoice: '.$order->invoice_number, '💰 Total: '.$formattedTotal, '💳 Pay via: '.$paymentOption['label'], '', '📲 Scan the manual QR above.', '', 'Before paying, make sure:', '• QR name: '.$paymentOption['name'], '• Amount must match exactly: '.$formattedTotal,
        ] : [
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
            $lines[] = $locale === 'en' ? '⏳ Payment deadline:' : '⏳ Batas bayar:';
            $lines[] = $this->formatDate($order->expires_at, $locale);
            $lines[] = $locale === 'en' ? 'If payment proof is not sent by this deadline, the order expires automatically and stock is released.' : 'Kalau belum bayar/kirim bukti sampai batas waktu ini, order otomatis expired dan stok kembali.';
        }

        $lines[] = '';
        $lines[] = ($locale === 'en' ? '📦 Order: ' : '📦 Pesanan: ').$this->orderItemsSummary($order);
        $lines[] = '';
        $lines[] = $locale === 'en' ? 'After paying, send a payment screenshot to this bot as a PHOTO with this caption:' : 'Setelah bayar, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:';
        $lines[] = '';
        $lines[] = '/payment';
        $lines[] = '';
        $lines[] = $locale === 'en' ? 'Need help? Chat admin @'.$supportUsername.'.' : 'Kalau kesusahan, chat admin @'.$supportUsername.'.';

        return new TelegramPaymentInstruction(
            photoContents: $this->manualQrPhotoContents($order),
            filename: 'qr-lhie.jpg',
            captionLines: $lines,
        );
    }

    public function binancePay(Order $order, Payment $payment, string $payId, ?string $locale, bool $autoVerify = false): string
    {
        $order->loadMissing('orderItems');
        $amount = rtrim(rtrim(number_format($payment->amount / 1_000_000, 6, '.', ''), '0'), '.');
        $items = $this->orderItemsSummary($order);

        if ($autoVerify) {
            if ($locale === 'en') {
                return "💎 USDT CHECKOUT (Binance Pay)\n━━━━━━━━━━━━━━━━━━━━\n\n🎫 Order: {$order->invoice_number}\n📦 {$items}\n\n💵 Send EXACTLY this amount (tap to copy):\n{$amount} USDT\n\n📥 Binance Pay ID (tap to copy):\n{$payId}\n\n━━━━━━━━━━━━━━━━━━━━\nHOW TO PAY:\n1️⃣ Open Binance → Pay → Send, then scan the QR code above or send to the Binance Pay ID above.\n2️⃣ Transfer EXACTLY {$amount} USDT — the unique final digits identify your order, so do not round the amount.\n3️⃣ After transferring, tap ✅ I've Paid below. Verification is automatic 24/7 and your product is delivered to this chat within moments.\n\n⚠️ If verification cannot find your transfer, you can submit the Order ID from your Binance receipt as a backup.";
            }

            return "💎 CHECKOUT USDT (Binance Pay)\n━━━━━━━━━━━━━━━━━━━━\n\n🎫 Pesanan: {$order->invoice_number}\n📦 {$items}\n\n💵 Kirim TEPAT sejumlah ini (tap untuk salin):\n{$amount} USDT\n\n📥 Binance Pay ID (tap untuk salin):\n{$payId}\n\n━━━━━━━━━━━━━━━━━━━━\nCARA BAYAR:\n1️⃣ Buka Binance → Pay → Kirim, lalu scan QR code di atas atau kirim ke Binance Pay ID di atas.\n2️⃣ Transfer TEPAT {$amount} USDT — digit unik di belakang adalah penanda pesananmu, jadi jangan dibulatkan.\n3️⃣ Setelah transfer, tap ✅ Saya Sudah Bayar di bawah. Verifikasi berjalan otomatis 24 jam dan produk langsung dikirim ke chat ini.\n\n⚠️ Kalau verifikasi belum menemukan transfermu, kamu bisa kirim Order ID dari bukti transfer Binance sebagai cadangan.";
        }

        if ($locale === 'en') {
            return "💎 USDT CHECKOUT (Binance Pay)\n━━━━━━━━━━━━━━━━━━━━\n\n🎫 Order: {$order->invoice_number}\n📦 {$items}\n\n💵 Send EXACTLY this amount:\n{$amount} USDT\n\n📥 Binance Pay ID:\n{$payId}\n\n━━━━━━━━━━━━━━━━━━━━\nHOW TO PAY:\n1️⃣ In Binance Pay, enter the Binance Pay ID above or scan the QR code above.\n2️⃣ Transfer exactly {$amount} USDT. Do not change the amount.\n3️⃣ Send the payment screenshot to this bot as a photo with the caption /payment.\n4️⃣ If you already sent the screenshot, reply to that photo with /payment.\n\n⚠️ Payment is confirmed manually only after the admin verifies the screenshot and the exact amount.";
        }

        return "💎 CHECKOUT USDT (Binance Pay)\n━━━━━━━━━━━━━━━━━━━━\n\n🎫 Pesanan: {$order->invoice_number}\n📦 {$items}\n\n💵 Kirim TEPAT sejumlah ini:\n{$amount} USDT\n\n📥 Binance Pay ID:\n{$payId}\n\n━━━━━━━━━━━━━━━━━━━━\nCARA BAYAR:\n1️⃣ Di Binance Pay, masukkan Binance Pay ID di atas atau scan QR code di atas.\n2️⃣ Transfer tepat {$amount} USDT. Jangan ubah nominalnya.\n3️⃣ Kirim screenshot pembayaran ke bot ini sebagai foto dengan caption /payment.\n4️⃣ Kalau screenshot sudah terkirim, balas foto tersebut dengan /payment.\n\n⚠️ Pembayaran dikonfirmasi manual setelah admin memverifikasi screenshot dan nominal yang tepat.";
    }

    public function hostedPakasir(Order $order, Payment $payment, string $paymentUrl, bool $fallback = false, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $lines = [];

        if ($fallback) {
            $lines = $locale === 'en' ? [
                '⚠️ QRIS checkout redirected',
                'The direct QRIS code could not be created automatically. The order is still awaiting payment and the stock remains reserved.',
                'Use the QR code/payment link below to continue payment.',
                '',
            ] : [
                '⚠️ Checkout QRIS dialihkan',
                'QRIS langsung belum bisa dibuat otomatis. Order tetap pending dan stok tetap dipegang.',
                'Gunakan QR/link website di bawah untuk lanjut bayar.',
                '',
            ];
        }

        $lines = array_merge($lines, $this->pakasirOrderHeaderLines($order, $locale), [
            '💰 Total: '.$this->formatRupiah($order->total_amount),
            '',
            $locale === 'en' ? '⚠️ Pay the EXACT amount shown.' : '⚠️ Bayar dengan nominal yang SAMA PERSIS ya.',
        ]);

        if ($order->expires_at) {
            $lines[] = ($locale === 'en' ? '⏳ Order active until: ' : '⏳ Order aktif sampai: ').$this->formatDate($order->expires_at, $locale);
        }

        $lines[] = '';
        $lines[] = $locale === 'en'
            ? '📲 Scan the QRIS code above, or tap the payment button to open the payment page.'
            : '📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.';
        $lines[] = '';
        $lines[] = $locale === 'en'
            ? '✅ After payment succeeds, it will be verified automatically and the product will be sent directly to this chat.'
            : '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';
        $lines[] = $locale === 'en'
            ? 'If no automatic message arrives after payment, tap the Check Payment button.'
            : 'Kalau belum ada pesan otomatis setelah bayar, tap tombol Cek Pembayaran.';

        return new TelegramPaymentInstruction(
            photoContents: $this->qrCodes->png($paymentUrl),
            filename: 'qris-checkout-'.$payment->merchant_ref.'.png',
            captionLines: $lines,
            replyMarkup: $this->paymentActions($order, $paymentUrl, $locale),
        );
    }

    public function nativePakasir(Order $order, Payment $payment, PakasirQrisTransaction $transaction, string $paymentUrl, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $payableAmount = $transaction->totalPayment() ?? $payment->amount;
        $expiry = $this->earliestDate($transaction->expiresAt(), $payment->provider_expires_at, $payment->expired_at, $order->expires_at);

        $lines = array_merge($this->pakasirOrderHeaderLines($order, $locale), [
            ($locale === 'en' ? '💰 Product total: ' : '💰 Total produk: ').$this->formatRupiah($order->total_amount),
            ($locale === 'en' ? '🏷️ QRIS fee: ' : '🏷️ Biaya QRIS: ').($transaction->fee() === null
                ? ($locale === 'en' ? 'Not available yet' : 'Belum tersedia')
                : $this->formatRupiah($transaction->fee())),
            ($locale === 'en' ? '💳 Total QRIS payment: ' : '💳 Total bayar QRIS: ').$this->formatRupiah($payableAmount),
        ]);

        if ($expiry) {
            $lines[] = ($locale === 'en' ? '⏳ QRIS payment deadline: ' : '⏳ Batas bayar QRIS: ').$this->formatDate($expiry, $locale);
            $lines[] = $locale === 'en'
                ? 'After this deadline, the order expires automatically and the stock is released. Do not pay with an old QR code; check out again if you still want to buy.'
                : 'Lewat batas ini order otomatis expired dan stok dilepas. Jangan bayar QR lama; checkout ulang kalau masih mau beli.';
        }

        $lines[] = '';
        $lines[] = $locale === 'en'
            ? '📲 Scan the QRIS code above directly from Telegram. You do not need to open a browser if the QR code can be scanned.'
            : '📲 Scan QRIS di atas langsung dari Telegram. Tidak perlu buka browser kalau QR bisa discan.';
        $lines[] = $locale === 'en'
            ? '🌐 If the QR code cannot be scanned, tap the Pay via Website button as a fallback.'
            : '🌐 Kalau QR tidak bisa discan, tap tombol Bayar via Website sebagai fallback.';
        $lines[] = '';
        $lines[] = $locale === 'en'
            ? '✅ After payment succeeds, it will be verified automatically and the product will be sent directly to this chat.'
            : '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';
        $lines[] = $locale === 'en'
            ? 'If no automatic message arrives after payment, tap the Check Payment button.'
            : 'Kalau belum ada pesan otomatis setelah bayar, tap tombol Cek Pembayaran.';

        return new TelegramPaymentInstruction(
            photoContents: $this->qrCodes->png($transaction->paymentNumber()),
            filename: 'qris-'.$payment->merchant_ref.'.png',
            captionLines: $lines,
            replyMarkup: $this->paymentActions($order, $paymentUrl, $locale),
        );
    }

    /**
     * @return list<string>
     */
    private function pakasirOrderHeaderLines(Order $order, string $locale): array
    {
        return [
            '🧾 Invoice: '.$order->invoice_number,
            '━━━━━━━━━━━━━━',
            ($locale === 'en' ? '📦 Products: ' : '📦 Produk: ').$this->orderItemsSummary($order),
            ($locale === 'en' ? '🔢 Quantity: ' : '🔢 Jumlah: ').$order->orderItems->sum('quantity'),
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
     * @return array{inline_keyboard: list<list<array{text: string, url?: string, callback_data?: string}>>}
     */
    private function paymentActions(Order $order, string $paymentUrl, string $locale): array
    {
        return [
            'inline_keyboard' => [
                [['text' => $locale === 'en' ? '💳 Pay via Website' : '💳 Bayar via Website', 'url' => $paymentUrl]],
                [['text' => $locale === 'en' ? '✅ Check Payment' : '✅ Cek Pembayaran', 'callback_data' => 'order:'.$order->invoice_number]],
                [['text' => $locale === 'en' ? '❌ Cancel Order' : '❌ Batalkan Order', 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
            ],
        ];
    }

    private function formatDate(CarbonInterface $date, string $locale): string
    {
        $timezone = (string) config('app.timezone');
        $localized = $date->copy()->timezone($timezone);
        $months = $locale === 'en'
            ? [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            : [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return $localized->format('d').' '.$months[(int) $localized->format('n')].$localized->format(' Y H:i').' '.$this->timezoneLabel($timezone);
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
}
