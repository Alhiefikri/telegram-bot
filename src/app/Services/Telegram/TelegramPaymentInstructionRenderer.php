<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Pakasir\PakasirQrisFeePolicy;
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

    public function binancePay(Order $order, Payment $payment, string $payId, ?string $locale, bool $autoVerify = false, string $receiverLabel = 'BINANCE PAY ID'): string
    {
        $order->loadMissing('orderItems');
        $amount = rtrim(rtrim(number_format($payment->amount / 1_000_000, 6, '.', ''), '0'), '.');
        $items = $this->orderItemsSummary($order);
        $receiverInstruction = $receiverLabel === 'BINANCE PAY ID' ? 'Pay ID' : 'receiver identifier';

        if ($autoVerify && $payment->binance_verification_mode === 'amount_unique') {
            if ($locale === 'en') {
                return "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: {$order->invoice_number}\n📦 {$items}\n\n💵 Amount to send (tap to copy):\n{$amount} USDT\n\n⚠️ Important: Send exactly {$amount} USDT, including every decimal digit. Do not round or change the amount.\n\n📥 {$receiverLabel} (tap to copy):\n{$payId}\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Open Binance → Pay → Send, then scan the QR code above or use the {$receiverInstruction}.\n2. Complete the transfer using the exact amount shown.\n3. Wait while the payment is checked automatically.\n\nYour product will be delivered automatically when the transfer matches. Do not send a screenshot while this order is active.";
            }

            return "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: {$order->invoice_number}\n📦 {$items}\n\n💵 Nominal yang dikirim (tap untuk salin):\n{$amount} USDT\n\n⚠️ Penting: Kirim tepat {$amount} USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 {$receiverLabel} (tap untuk salin):\n{$payId}\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Kirim, lalu scan QR code di atas atau gunakan {$receiverInstruction}.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Tunggu pembayaran diperiksa otomatis.\n\nProduk akan dikirim otomatis jika transfer cocok. Jangan kirim screenshot selama order ini masih aktif.";
        }

        if ($autoVerify) {
            if ($locale === 'en') {
                return "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: {$order->invoice_number}\n📦 {$items}\n\n💵 Amount to send (tap to copy):\n{$amount} USDT\n\n⚠️ Important: Send exactly {$amount} USDT, including every decimal digit. Do not round or change the amount.\n\n📥 {$receiverLabel} (tap to copy):\n{$payId}\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Open Binance → Pay → Send, then scan the QR code above or use the {$receiverInstruction}.\n2. Complete the transfer using the exact amount shown.\n3. Tap the I've Paid button.\n4. Copy the Order ID from Binance → Pay → your transfer → Order Details and send it in this chat.\n\nVerification starts automatically after your Order ID is received.";
            }

            return "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: {$order->invoice_number}\n📦 {$items}\n\n💵 Nominal yang dikirim (tap untuk salin):\n{$amount} USDT\n\n⚠️ Penting: Kirim tepat {$amount} USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 {$receiverLabel} (tap untuk salin):\n{$payId}\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Kirim, lalu scan QR code di atas atau gunakan {$receiverInstruction}.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Tap tombol Saya Sudah Bayar.\n4. Buka Binance → Pay → pilih transfer kamu → Order Details, lalu salin dan kirim Order ID ke chat ini.\n\nVerifikasi dimulai otomatis setelah Order ID diterima.";
        }

        if ($locale === 'en') {
            return "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: {$order->invoice_number}\n📦 {$items}\n\n💵 Amount to send:\n{$amount} USDT\n\n⚠️ Important: Send exactly {$amount} USDT, including every decimal digit. Do not round or change the amount.\n\n📥 {$receiverLabel}:\n{$payId}\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Scan the QR code or enter the {$receiverInstruction} in Binance Pay.\n2. Complete the transfer using the exact amount shown.\n3. Send the payment screenshot as a photo with the caption /payment.\n\nPayment is confirmed after the screenshot and exact amount are reviewed.";
        }

        return "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: {$order->invoice_number}\n📦 {$items}\n\n💵 Nominal yang dikirim:\n{$amount} USDT\n\n⚠️ Penting: Kirim tepat {$amount} USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 {$receiverLabel}:\n{$payId}\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Scan QR code atau masukkan {$receiverInstruction} di Binance Pay.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Kirim screenshot pembayaran sebagai foto dengan caption /payment.\n\nPembayaran dikonfirmasi setelah screenshot dan nominal diperiksa.";
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
        $customerPaysFee = PakasirQrisFeePolicy::fromConfig()->feePayer() === 'customer';

        $lines = array_merge($this->pakasirOrderHeaderLines($order, $locale), [
            ($locale === 'en' ? '💰 Product total: ' : '💰 Total produk: ').$this->formatRupiah($order->total_amount),
            ($locale === 'en' ? '🏷️ QRIS fee: ' : '🏷️ Biaya QRIS: ').($transaction->fee() === null
                ? ($locale === 'en' ? 'Not available yet' : 'Belum tersedia')
                : $this->formatRupiah($transaction->fee())),
            ($locale === 'en' ? '💳 Total QRIS payment: ' : '💳 Total bayar QRIS: ').$this->formatRupiah($payableAmount),
            $locale === 'en'
                ? ($customerPaysFee ? 'ℹ️ The QRIS fee is paid by the customer and is included in the total above.' : 'ℹ️ The QRIS fee is covered by the store; the customer pays only the product total.')
                : ($customerPaysFee ? 'ℹ️ Biaya QRIS dibayar pelanggan dan sudah termasuk dalam total di atas.' : 'ℹ️ Biaya QRIS ditanggung toko; pelanggan hanya membayar total produk.'),
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

    public function klikQris(Order $order, KlikQrisPayableNativeQris $payable, string $photoContents, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $total = $payable->totalAmountDisplay();
        $expiry = $payable->effectiveExpiresAt();
        $lines = [
            $locale === 'en' ? '✅ KlikQRIS checkout created' : '✅ Checkout KlikQRIS berhasil dibuat',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            ($locale === 'en' ? '📦 Products: ' : '📦 Produk: ').$this->orderItemsSummary($order),
            ($locale === 'en' ? '💳 Total QRIS payment: ' : '💳 Total bayar QRIS: ').$total,
            '',
            $locale === 'en'
                ? '⚠️ Do not round or change any digit.'
                : '⚠️ Jangan membulatkan atau mengubah satu digit pun.',
            $locale === 'en'
                ? 'Scan this QRIS directly in Telegram. Pay only from the QR image above.'
                : 'Scan QRIS ini langsung dari Telegram. Bayar hanya dari gambar QR di atas.',
        ];

        $lines[] = '';
        $lines[] = ($locale === 'en' ? '⏳ Provider deadline: ' : '⏳ Batas provider: ').$this->formatDate($expiry, $locale);
        $lines[] = $locale === 'en'
            ? 'This QRIS is valid for 15 minutes. After the deadline, create a new QRIS instead of paying this one.'
            : 'QRIS ini berlaku 15 menit. Setelah batas waktu, buat QRIS baru dan jangan bayar QR ini.';

        $lines[] = '';
        $lines[] = $locale === 'en'
            ? 'After payment succeeds, it will be verified automatically and the product will be sent directly to this chat.'
            : 'Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.';

        return new TelegramPaymentInstruction(
            photoContents: $photoContents,
            filename: 'klikqris-'.$order->invoice_number.'.png',
            captionLines: $lines,
            replyMarkup: [
                'inline_keyboard' => [
                    [['text' => $locale === 'en' ? '✅ Check Payment' : '✅ Cek Pembayaran', 'callback_data' => 'order:'.$order->invoice_number]],
                    [['text' => $locale === 'en' ? '❌ Cancel Order' : '❌ Batalkan Order', 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
                ],
            ],
        );
    }

    /**
     * Text-only retry state for a non-payable KlikQRIS outcome. No QR, total,
     * or provider data is presented; the customer either waits and retries on
     * the same invoice or checks the order deadline.
     */
    public function klikQrisRetry(Order $order, KlikQrisNativeQrisOutcome $outcome, string $locale = 'id'): string
    {
        if ($outcome === KlikQrisNativeQrisOutcome::Expired) {
            return implode("\n", array_filter([
                '⏳ QRIS '.($locale === 'en' ? 'expired' : 'kedaluwarsa'),
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$order->invoice_number,
                '',
                $locale === 'en'
                    ? 'This QRIS can no longer be paid. Do not pay it. Create a new order if you still want to buy.'
                    : 'QRIS ini sudah tidak bisa dibayar. Jangan bayar QR ini. Buat pesanan baru kalau masih ingin membeli.',
            ]));
        }

        $lead = match ($outcome) {
            KlikQrisNativeQrisOutcome::Creating => $locale === 'en'
                ? '⏳ Preparing your QRIS'
                : '⏳ Sedang menyiapkan QRIS',
            KlikQrisNativeQrisOutcome::RecoveryPending => $locale === 'en'
                ? '⏳ Restoring your QRIS'
                : '⏳ Sedang memulihkan QRIS',
            KlikQrisNativeQrisOutcome::ProviderTotalPending => $locale === 'en'
                ? '⏳ Waiting for the final amount'
                : '⏳ Menunggu nominal final dari provider',
            KlikQrisNativeQrisOutcome::InvalidProviderResult => $locale === 'en'
                ? '⚠️ QRIS could not be prepared'
                : '⚠️ QRIS tidak bisa disiapkan',
            KlikQrisNativeQrisOutcome::Payable => 'QRIS is ready',
        };

        $body = match ($outcome) {
            KlikQrisNativeQrisOutcome::Creating => $locale === 'en'
                ? 'The provider is preparing your QRIS and its final amount. Do not create another checkout; open this invoice again shortly or tap Check Payment.'
                : 'Provider sedang menyiapkan QRIS dan nominal final. Jangan membuat checkout baru; buka invoice ini lagi sebentar atau tap Cek Pembayaran.',
            KlikQrisNativeQrisOutcome::RecoveryPending => $locale === 'en'
                ? 'Your QRIS is being recovered on this same invoice, and no new payment instruction was created. Tap Check Payment shortly or open this invoice again.'
                : 'QRIS sedang dipulihkan pada invoice yang sama, tanpa membuat instruksi bayar baru. Tap Cek Pembayaran sebentar lagi atau buka invoice ini lagi.',
            KlikQrisNativeQrisOutcome::ProviderTotalPending => $locale === 'en'
                ? 'The provider has not supplied the final payable amount yet. Do not pay the product subtotal. Open this invoice again shortly.'
                : 'Provider belum memberi total bayar final. Jangan bayar total produk. Buka invoice ini lagi sebentar.',
            KlikQrisNativeQrisOutcome::InvalidProviderResult => $locale === 'en'
                ? 'The provider returned an unusable QRIS result. Do not pay. Contact support with your invoice number to resolve this order.'
                : 'Provider memberi hasil QRIS yang tidak valid. Jangan bayar. Hubungi bantuan dengan nomor invoice ini untuk menyelesaikan pesanan.',
            KlikQrisNativeQrisOutcome::Payable => 'Your QRIS is ready to pay.',
        };

        return implode("\n", array_filter([
            $lead,
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '',
            $body,
        ]));
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
