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
    public function __construct(
        private TelegramQrCodeRenderer $qrCodes,
        private TelegramTranslationCatalog $catalog,
    ) {}

    /**
     * @param  array{label: string, name: string}  $paymentOption
     */
    public function manual(Order $order, array $paymentOption, string $supportUsername, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');

        $lines = [
            $this->text($locale, 'payment.checkout_created'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            $this->text($locale, 'payment.total', ['amount' => $formattedTotal]),
            $this->text($locale, 'payment.pay_via', ['method' => $paymentOption['label']]),
            '',
            $this->text($locale, 'payment.manual.scan_qr'),
            '',
            $this->text($locale, 'payment.manual.before_pay'),
            $this->text($locale, 'payment.manual.qr_name', ['name' => $paymentOption['name']]),
            $this->text($locale, 'payment.manual.exact_amount', ['amount' => $formattedTotal]),
        ];

        if ($order->expires_at) {
            $lines[] = '';
            $lines[] = $this->text($locale, 'payment.deadline');
            $lines[] = $this->formatDate($order->expires_at, $locale);
            $lines[] = $this->text($locale, 'payment.manual.deadline_guidance');
        }

        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.order', ['items' => $this->orderItemsSummary($order)]);
        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.manual.proof_prompt');
        $lines[] = '';
        $lines[] = '/payment';
        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.manual.help', ['username' => $supportUsername]);

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
        $locale = $locale ?: 'en';
        $receiverInstruction = $receiverLabel === 'BINANCE PAY ID'
            ? $this->text($locale, 'payment.binance.receiver_pay_id')
            : $this->text($locale, 'payment.binance.receiver_identifier');
        $key = $autoVerify && $payment->binance_verification_mode === 'amount_unique'
            ? 'payment.binance.amount_unique'
            : ($autoVerify ? 'payment.binance.order_id' : 'payment.binance.manual');

        return $this->text($locale, $key, [
            'invoice' => $order->invoice_number,
            'items' => $this->orderItemsSummary($order),
            'amount' => $amount,
            'receiver_label' => $receiverLabel,
            'receiver' => $payId,
            'receiver_instruction' => $receiverInstruction,
        ]);
    }

    public function hostedPakasir(Order $order, Payment $payment, string $paymentUrl, bool $fallback = false, string $locale = 'id'): TelegramPaymentInstruction
    {
        $order->loadMissing('orderItems');
        $lines = [];

        if ($fallback) {
            $lines = [
                $this->text($locale, 'payment.pakasir.fallback.title'),
                $this->text($locale, 'payment.pakasir.fallback.explanation'),
                $this->text($locale, 'payment.pakasir.fallback.continue'),
                '',
            ];
        }

        $lines = array_merge($lines, $this->pakasirOrderHeaderLines($order, $locale), [
            $this->text($locale, 'payment.total', ['amount' => $this->formatRupiah($order->total_amount)]),
            '',
            $this->text($locale, 'payment.pakasir.exact_amount'),
        ]);

        if ($order->expires_at) {
            $lines[] = $this->text($locale, 'payment.pakasir.order_active_until', ['date' => $this->formatDate($order->expires_at, $locale)]);
        }

        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.pakasir.scan_hosted');
        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.auto_delivery');
        $lines[] = $this->text($locale, 'payment.check_if_missing');

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
        $fee = $transaction->fee() === null
            ? $this->text($locale, 'payment.qris_fee_unavailable')
            : $this->formatRupiah($transaction->fee());

        $lines = array_merge($this->pakasirOrderHeaderLines($order, $locale), [
            $this->text($locale, 'payment.product_total', ['amount' => $this->formatRupiah($order->total_amount)]),
            $this->text($locale, 'payment.qris_fee', ['fee' => $fee]),
            $this->text($locale, 'payment.qris_total', ['amount' => $this->formatRupiah($payableAmount)]),
            $this->text($locale, $customerPaysFee ? 'payment.qris_fee_customer' : 'payment.qris_fee_store'),
        ]);

        if ($expiry) {
            $lines[] = $this->text($locale, 'payment.qris_deadline', ['date' => $this->formatDate($expiry, $locale)]);
            $lines[] = $this->text($locale, 'payment.qris_deadline_guidance');
        }

        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.pakasir.scan_native');
        $lines[] = $this->text($locale, 'payment.pakasir.website_fallback');
        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.auto_delivery');
        $lines[] = $this->text($locale, 'payment.check_if_missing');

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
            $this->text($locale, 'payment.klikqris.checkout_created'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            $this->text($locale, 'payment.products', ['items' => $this->orderItemsSummary($order)]),
            $this->text($locale, 'payment.qris_total', ['amount' => $total]),
            '',
            $this->text($locale, 'payment.klikqris.exact_amount'),
            $this->text($locale, 'payment.klikqris.scan'),
        ];

        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.klikqris.provider_deadline', ['date' => $this->formatDate($expiry, $locale)]);
        $lines[] = $this->text($locale, 'payment.klikqris.validity');
        $lines[] = '';
        $lines[] = $this->text($locale, 'payment.auto_delivery');

        return new TelegramPaymentInstruction(
            photoContents: $photoContents,
            filename: 'klikqris-'.$order->invoice_number.'.png',
            captionLines: $lines,
            replyMarkup: [
                'inline_keyboard' => [
                    [['text' => $this->text($locale, 'payment.action.check'), 'callback_data' => 'order:'.$order->invoice_number]],
                    [['text' => $this->text($locale, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
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
            return implode("\n", [
                $this->text($locale, 'payment.klikqris.retry.expired_title'),
                '━━━━━━━━━━━━━━',
                $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
                '',
                $this->text($locale, 'payment.klikqris.retry.expired_body'),
            ]);
        }

        $keySuffix = match ($outcome) {
            KlikQrisNativeQrisOutcome::Creating => 'creating',
            KlikQrisNativeQrisOutcome::RecoveryPending => 'recovery_pending',
            KlikQrisNativeQrisOutcome::ProviderTotalPending => 'total_pending',
            KlikQrisNativeQrisOutcome::InvalidProviderResult => 'invalid',
            KlikQrisNativeQrisOutcome::Payable => 'ready',
            KlikQrisNativeQrisOutcome::Expired => 'expired',
        };

        return implode("\n", [
            $this->text($locale, 'payment.klikqris.retry.'.$keySuffix.'_lead'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            '',
            $this->text($locale, 'payment.klikqris.retry.'.$keySuffix.'_body'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function pakasirOrderHeaderLines(Order $order, string $locale): array
    {
        return [
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.products', ['items' => $this->orderItemsSummary($order)]),
            $this->text($locale, 'payment.quantity', ['quantity' => $order->orderItems->sum('quantity')]),
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
                [['text' => $this->text($locale, 'payment.action.website'), 'url' => $paymentUrl]],
                [['text' => $this->text($locale, 'payment.action.check'), 'callback_data' => 'order:'.$order->invoice_number]],
                [['text' => $this->text($locale, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
            ],
        ];
    }

    private function formatDate(CarbonInterface $date, string $locale): string
    {
        $timezone = (string) config('app.timezone');
        $localized = $date->copy()->timezone($timezone);
        $month = $this->catalog->translate('date.month.'.(int) $localized->format('n'), $locale);

        return $localized->format('d').' '.$month.$localized->format(' Y H:i').' '.$this->timezoneLabel($timezone);
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

    /**
     * @param  array<string, scalar|null>  $replace
     */
    private function text(string $locale, string $key, array $replace = []): string
    {
        return $this->catalog->translate($key, $locale, $replace);
    }
}
