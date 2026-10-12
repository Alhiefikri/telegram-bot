<?php

namespace App\Services\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Pakasir\PakasirQrisFeePolicy;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahPayableInvoice;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPayable;
use App\Support\DogfoodTiming;
use Carbon\CarbonInterface;

class TelegramPaymentInstructionRenderer
{
    public function __construct(
        private TelegramQrCodeRenderer $qrCodes,
        private TelegramTranslationCatalog $catalog,
        private TelegramProductNameRenderer $productNames,
    ) {}

    /**
     * @param  array{label: string, name: string}  $paymentOption
     */
    public function manual(Order $order, array $paymentOption, string $supportUsername, string $locale = 'id'): TelegramPaymentInstruction
    {
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');
        $lines = [
            $this->text($locale, 'payment.checkout_created'),
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            $this->text($locale, 'payment.total', ['amount' => $formattedTotal]),
            $this->text($locale, 'payment.pay_via', ['method' => $paymentOption['label']]),
            '',
            $this->text($locale, 'payment.manual.scan_qr_short'),
        ];
        if ($order->expires_at) {
            $lines[] = $this->text($locale, 'payment.manual.deadline_short', [
                'date' => $this->formatDate($order->expires_at, $locale),
            ]);
        }

        return new TelegramPaymentInstruction(
            photoContents: $this->manualQrPhotoContents($order),
            filename: 'qr-lhie.jpg',
            captionLines: $lines,
            replyMarkup: [
                'inline_keyboard' => [
                    [['text' => $this->text($locale, 'payment.action.paid'), 'callback_data' => 'manual:paid:'.$order->id]],
                    [['text' => $this->text($locale, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]],
                ],
            ],
        );
    }

    public function manualProof(Order $order, string $supportUsername, string $locale = 'id'): string
    {
        $formattedTotal = 'Rp '.number_format($order->total_amount, 0, ',', '.');

        return implode("\n", [
            $this->text($locale, 'payment.manual.proof_title'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            $this->text($locale, 'payment.total', ['amount' => $formattedTotal]),
            '',
            $this->text($locale, 'payment.manual.proof_prompt'),
            '/payment',
            '',
            $this->text($locale, 'payment.manual.help', ['username' => $supportUsername]),
        ]);
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

    /**
     * Render the immutable BSC Payment snapshot. No current receiver setting
     * or environment address is read here; the Payment detail is the source
     * of truth once checkout has committed.
     *
     * @return array{text: string, reply_markup: array{inline_keyboard: list<list<array<string, string>>>}}
     */
    public function bsc(Order $order, Payment $payment, string $locale = 'en'): array
    {
        $order->loadMissing('orderItems');
        $payment->loadMissing('bscDetail');
        $detail = $payment->bscDetail;
        if (! $detail instanceof PaymentBscDetail) {
            throw new \RuntimeException('BSC Payment detail snapshot is missing.');
        }

        $confirmations = max(
            $detail->verification_status === PaymentBscDetail::STATUS_AWAITING_TRANSFER ? 0 : (int) ($detail->current_confirmations ?? 0),
            0,
        );
        $minimumConfirmations = max(
            ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
            min(100, (int) ($detail->required_confirmations ?: ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS)),
        );
        $deadline = $payment->expired_at ?? $order->expires_at;
        $amount = $this->formatUsdt((int) $detail->payable_amount_micro);
        $instructionsKey = $detail->verification_mode === PaymentBscDetail::VERIFICATION_MODE_AMOUNT_UNIQUE
            ? 'payment.bsc.instructions_auto'
            : 'payment.bsc.instructions';

        $text = $this->text($locale, $instructionsKey, [
            'invoice' => $order->invoice_number,
            'items' => $this->orderItemsSummary($order),
            'amount' => $amount,
            'address' => $detail->receiver_address,
            'deadline' => $deadline ? $this->formatDate($deadline, $locale) : $this->text($locale, 'payment.bsc.deadline_default'),
            'ttl' => $this->bscWindowMinutes($payment, $deadline),
            'confirmations' => $minimumConfirmations,
            'current_confirmations' => $confirmations,
        ]);

        $buttons = [];
        if ($detail->verification_mode !== PaymentBscDetail::VERIFICATION_MODE_AMOUNT_UNIQUE) {
            $buttons[] = [['text' => $this->text($locale, 'payment.bsc.submit_hash'), 'callback_data' => 'bsc:submit:'.$order->id]];
        }
        $buttons[] = [['text' => $this->text($locale, 'payment.bsc.check_status'), 'callback_data' => 'bsc:check:'.$order->id]];
        $buttons[] = [['text' => $this->text($locale, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]];

        return [
            'text' => $text,
            'reply_markup' => [
                'inline_keyboard' => $buttons,
            ],
        ];
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

    public function balanceTopUp(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
        string $photoContents,
        string $locale = 'id',
        bool $awaitingConfirmation = false,
    ): TelegramPaymentInstruction {
        $lines = [
            $this->text($locale, 'balance.topup.instruction.title'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'balance.topup.credit', [
                'amount' => $this->formatRupiah($payable->creditAmount()),
            ]),
            $this->text($locale, 'balance.topup.fee', [
                'amount' => $this->formatRupiah($payable->providerFee() ?? 0),
            ]),
            $this->text($locale, 'balance.topup.total', [
                'amount' => $this->formatRupiah($payable->totalAmount()),
            ]),
            $this->text($locale, 'balance.topup.deadline', [
                'expires' => $this->formatDate($payable->effectiveExpiresAt(), $locale),
            ]),
            '',
            $this->text(
                $locale,
                $awaitingConfirmation
                    ? 'balance.topup.awaiting_confirmation'
                    : 'balance.topup.unpaid',
            ),
            $this->text($locale, 'balance.topup.help'),
        ];

        return new TelegramPaymentInstruction(
            photoContents: $photoContents,
            filename: 'balance-top-up-'.$topUp->getKey().'.png',
            captionLines: $lines,
            replyMarkup: [
                'inline_keyboard' => [
                    [[
                        'text' => $this->text($locale, 'balance.topup.check'),
                        'callback_data' => StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
                    ]],
                ],
            ],
        );
    }

    public function sakurupiah(Order $order, SakurupiahPayableInvoice $payable, string $locale = 'id'): TelegramPaymentInstruction
    {
        $qrContent = $payable->qrisPayload();
        if ($qrContent === null || ! $payable->isDirectQris()) {
            throw new \RuntimeException('Sakurupiah direct QRIS invoice has no QRIS payload.');
        }

        return new TelegramPaymentInstruction(
            photoContents: $this->qrCodes->png($qrContent),
            filename: 'sakurupiah-'.$order->invoice_number.'.png',
            captionLines: $this->sakurupiahLines($order, $payable, $locale),
            replyMarkup: $this->sakurupiahPaymentActions($order, null, $locale),
        );
    }

    public function sakurupiahQrisImage(Order $order, SakurupiahPayableInvoice $payable, string $photoContents, string $locale = 'id'): TelegramPaymentInstruction
    {
        if (! $payable->isQrisImage()) {
            throw new \RuntimeException('Sakurupiah invoice has no provider QR image.');
        }

        return new TelegramPaymentInstruction(
            photoContents: $photoContents,
            filename: 'sakurupiah-'.$order->invoice_number.'.png',
            captionLines: $this->sakurupiahLines($order, $payable, $locale),
            replyMarkup: $this->sakurupiahPaymentActions($order, $payable->checkoutUrl(), $locale),
        );
    }

    /** @return array{text: string, reply_markup: array<string, mixed>} */
    public function sakurupiahHosted(Order $order, SakurupiahPayableInvoice $payable, string $locale = 'id'): array
    {
        $paymentUrl = $payable->checkoutUrl();
        if ($paymentUrl === null || ! $payable->isHosted()) {
            throw new \RuntimeException('Sakurupiah hosted invoice has no checkout URL.');
        }

        return [
            'text' => implode("\n", $this->sakurupiahLines($order, $payable, $locale)),
            'reply_markup' => $this->sakurupiahPaymentActions($order, $paymentUrl, $locale),
        ];
    }

    /** @return list<string> */
    private function sakurupiahLines(Order $order, SakurupiahPayableInvoice $payable, string $locale): array
    {
        $order->loadMissing('orderItems');
        $lines = [
            $this->text($locale, 'payment.sakurupiah.checkout_created'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            $this->text($locale, 'payment.products', ['items' => $this->orderItemsSummary($order)]),
            $this->text($locale, 'payment.product_total', ['amount' => $this->formatRupiah($order->total_amount)]),
            $this->text($locale, 'payment.qris_fee', ['fee' => $this->formatRupiah($payable->providerFee())]),
            $this->text($locale, 'payment.qris_total', ['amount' => $payable->totalAmountDisplay()]),
            $this->text($locale, $payable->customerPaysFee() ? 'payment.qris_fee_customer' : 'payment.qris_fee_store'),
            '',
            $this->text($locale, 'payment.sakurupiah.exact_amount'),
            $payable->isDirectQris() || $payable->isQrisImage()
                ? $this->text($locale, 'payment.sakurupiah.scan')
                : $this->text($locale, 'payment.sakurupiah.hosted'),
            '',
            $this->text($locale, 'payment.qris_deadline', [
                'date' => $this->formatDate($payable->effectiveExpiresAt(), $locale),
            ]),
            $this->text($locale, 'payment.qris_deadline_guidance'),
            '',
            $this->text($locale, 'payment.auto_delivery'),
            $this->text($locale, 'payment.check_if_missing'),
        ];

        return $lines;
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
     * Text-only retry state for a non-payable Sakurupiah outcome. Provider
     * response data never crosses this presentation boundary.
     */
    public function sakurupiahRetry(Order $order, SakurupiahInvoiceOutcome $outcome, string $locale = 'id'): string
    {
        if ($outcome === SakurupiahInvoiceOutcome::Expired) {
            return implode("\n", [
                $this->text($locale, 'payment.sakurupiah.retry.expired_title'),
                '━━━━━━━━━━━━━━',
                $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
                '',
                $this->text($locale, 'payment.sakurupiah.retry.expired_body'),
            ]);
        }

        $keySuffix = match ($outcome) {
            SakurupiahInvoiceOutcome::Creating => 'creating',
            SakurupiahInvoiceOutcome::RecoveryPending => 'recovery_pending',
            SakurupiahInvoiceOutcome::ProviderTotalPending => 'total_pending',
            SakurupiahInvoiceOutcome::ProviderPresentationPending => 'presentation_pending',
            SakurupiahInvoiceOutcome::InvalidProviderResult => 'invalid',
            SakurupiahInvoiceOutcome::Payable => 'ready',
            SakurupiahInvoiceOutcome::Expired => 'expired',
        };

        return implode("\n", [
            $this->text($locale, 'payment.sakurupiah.retry.'.$keySuffix.'_lead'),
            '━━━━━━━━━━━━━━',
            $this->text($locale, 'payment.invoice', ['invoice' => $order->invoice_number]),
            '',
            $this->text($locale, 'payment.sakurupiah.retry.'.$keySuffix.'_body'),
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

    private function formatUsdt(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');
    }

    /**
     * Report the payment window from the snapshot itself so the copy always
     * matches the configured TTL captured at checkout.
     */
    private function bscWindowMinutes(Payment $payment, ?CarbonInterface $deadline): int
    {
        if (! $deadline instanceof CarbonInterface || $payment->created_at === null) {
            return ManualPaymentSetting::DEFAULT_BSC_PAYMENT_TTL_MINUTES;
        }

        return max(1, (int) round(abs($deadline->diffInSeconds($payment->created_at)) / 60));
    }

    private function orderItemsSummary(Order $order): string
    {
        return $order->orderItems
            ->map(fn ($item) => $this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).($item->quantity > 1 ? ' x'.$item->quantity : ''))
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

    /**
     * @return array{inline_keyboard: list<list<array{text: string, url?: string, callback_data?: string}>>}
     */
    private function sakurupiahPaymentActions(Order $order, ?string $paymentUrl, string $locale): array
    {
        $keyboard = [];
        if ($paymentUrl !== null) {
            $keyboard[] = [['text' => $this->text($locale, 'payment.action.website'), 'url' => $paymentUrl]];
        }
        $keyboard[] = [['text' => $this->text($locale, 'payment.action.check'), 'callback_data' => 'order:'.$order->invoice_number]];
        $keyboard[] = [['text' => $this->text($locale, 'payment.action.cancel'), 'callback_data' => 'buyer:cancel-pending:'.$order->id]];

        return ['inline_keyboard' => $keyboard];
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
