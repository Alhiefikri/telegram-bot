<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\PaidInvoice\PaidInvoiceRenderer;

class TelegramReceiptNotifier
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private CredentialDeliveryFormatter $credentialFormatter,
        private CredentialDeliveryPresentation $credentialPresentation,
        private TelegramCredentialDeliveryRenderer $credentialRenderer,
        private TelegramMessageChunker $messageChunker,
        private TelegramSupportContact $supportContact,
        private PaidInvoiceRenderer $invoiceRenderer,
    ) {}

    /**
     * Compatibility entry point for callers that still invoke the notifier
     * directly. New queued delivery is owned by ReceiptDeliveryPipeline.
     */
    public function send(Order $order, bool $force = false): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $force
            ? $outboundEffects->recordReceiptResend($order)
            : $outboundEffects->recordReceipt($order);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $ambiguousInvoiceException = null;
        while ($effect !== null) {
            try {
                $pipeline->deliver($effect, $this, dispatchNext: false, throwOnAmbiguous: true);
            } catch (\Throwable $exception) {
                $freshEffect = $effect->fresh();
                if (! $this->isInvoiceEffect($freshEffect)
                    || $freshEffect->state !== TelegramOutboundEffectState::Unknown) {
                    throw $exception;
                }

                $ambiguousInvoiceException ??= $exception;
            }

            $effect = TelegramOutboundEffect::query()
                ->where('effect_group_key', $effect->effect_group_key)
                ->where('sequence', '>', $effect->sequence)
                ->whereIn('state', ['pending', 'failed'])
                ->orderBy('sequence')
                ->get()
                ->first(fn (TelegramOutboundEffect $candidate): bool => app(TelegramOutboundEffectLedgerProtocol::class)->isDispatchable($candidate));
        }

        if ($ambiguousInvoiceException !== null) {
            throw $ambiguousInvoiceException;
        }
    }

    private function isInvoiceEffect(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return ($metadata['unit_role'] ?? null) === 'invoice'
            || ($effect->telegram_method === 'sendPhoto'
                && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE);
    }

    /**
     * Render the new buyer receipt: one shared invoice photo followed by
     * compact localized Product Detail units.
     *
     * @return array{chunks: list<string>, units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    public function render(Order $order): array
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            return ['chunks' => [''], 'units' => [new TelegramMessageUnit('')], 'guides' => []];
        }

        $invoice = $this->renderInvoiceUnit($order);
        $detailUnits = $this->renderProductDetail($order, $customer);
        $units = [$invoice, ...$detailUnits['units']];

        return $this->rendered($units, $detailUnits['guides']);
    }

    /**
     * Count new receipt units without rendering or retaining PNG bytes.
     */
    public function deliveryUnitCount(Order $order): int
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            return 1;
        }

        return 1 + count($this->renderProductDetail($order, $customer)['units']);
    }

    /**
     * Render only the invoice unit for the current send attempt.
     */
    public function renderInvoiceUnit(Order $order): TelegramPhotoUnit
    {
        try {
            $result = $this->invoiceRenderer->render($order);
        } catch (\Throwable $exception) {
            throw new TelegramOutboundPreSendException(
                'Telegram paid invoice rendering failed before send.',
                $exception,
            );
        }

        return new TelegramPhotoUnit(
            photoContents: $result->bytes,
            filename: $result->filename,
        );
    }

    /**
     * Render Product Detail without touching the shared invoice renderer.
     *
     * @return array{units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    public function renderProductDetail(Order $order, ?Customer $customer = null): array
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $customer ??= $order->customer;
        if (! $customer instanceof Customer) {
            throw new TelegramOutboundPreSendException('Telegram receipt target is missing.');
        }

        $delivery = $this->credentialPresentation->forCustomer($customer, $order);
        $payment = $this->canonicalPaidPayment($order);
        $summaryLines = [
            $this->locale->translate($customer, 'receipt.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'receipt.order_id', [
                'order' => $order->invoice_number ?: $order->getKey(),
            ]),
            $this->locale->translate($customer, 'receipt.products'),
        ];
        foreach ($order->orderItems as $item) {
            $summaryLines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $summaryLines[] = $this->locale->translate($customer, 'receipt.payment_method', [
            'method' => $payment instanceof Payment
                ? $this->locale->paymentMethod($customer, $payment)
                : $this->locale->translate($customer, 'payment.method.unknown'),
        ]);
        $summaryLines[] = $this->locale->translate($customer, 'receipt.paid_at', [
            'date' => $this->locale->formatDate($customer, $payment?->paid_at ?? $order->updated_at ?? now()),
        ]);
        $summaryLines[] = $this->locale->translate($customer, 'receipt.total_quantity', [
            'quantity' => $order->orderItems->sum(fn ($item): int => max(0, (int) $item->quantity)),
        ]);
        $summaryLines[] = $this->locale->translate($customer, 'receipt.amount_paid', [
            'amount' => $this->formatPaidAmount($order, $payment),
        ]);
        $summaryLines[] = '';
        $summaryLines[] = $this->locale->translate($customer, 'receipt.product_detail');

        $guides = $delivery->usageGuides();
        if ($delivery->isEmpty()) {
            $units = $this->unitsFromLines([
                ...$summaryLines,
                $this->locale->translate($customer, 'receipt.no_credentials'),
                '',
                $this->locale->translate($customer, 'receipt.save_details'),
                $this->locale->translate($customer, 'receipt.copy_guidance'),
                $this->locale->translate($customer, 'receipt.thanks'),
            ], $customer, $guides, withReplyMarkup: true);

            return ['units' => $units, 'guides' => $guides];
        }

        $units = $this->unitsFromLines($summaryLines);
        array_push(
            $units,
            ...$this->credentialRenderer->groupedAccountUnits(
                customer: $customer,
                delivery: $delivery,
                accountLabelTranslationKey: 'receipt.account_label',
                otpButtonTranslationKey: 'receipt.otp_button',
                deliveryNoteTranslationKey: 'receipt.delivery_note',
            ),
        );
        $units[] = new TelegramMessageUnit(
            implode("\n", [
                $this->locale->translate($customer, 'receipt.save_details'),
                $this->locale->translate($customer, 'receipt.copy_guidance'),
                $this->locale->translate($customer, 'receipt.thanks'),
            ]),
            replyMarkup: $this->receiptReplyMarkup($customer, $guides),
        );

        return ['units' => $units, 'guides' => $guides];
    }

    /**
     * Render a receipt in memory. The returned credential-bearing strings are
     * deliberately never persisted in the outbound effect ledger.
     *
     * @return array{chunks: list<string>, units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    public function renderLegacy(Order $order): array
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);

        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            $unit = new TelegramMessageUnit('');

            return ['chunks' => [''], 'units' => [$unit], 'guides' => []];
        }

        $delivery = $this->credentialPresentation->forCustomer($customer, $order);
        $guides = $delivery->usageGuides();
        if ($this->hasStructuredAccount($delivery)) {
            return $this->renderStructuredReceipt($customer, $order, $delivery, $guides);
        }

        $lines = [
            $this->locale->translate($customer, 'receipt.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'payment.invoice', ['invoice' => $order->invoice_number]),
            '',
            $this->locale->translate($customer, 'receipt.products'),
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }

        $accountLines = $this->accountSecretLines($customer, $delivery);
        if ($accountLines !== []) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'receipt.account_details');
            $lines[] = '━━━━━━━━━━━━━━';
            array_push($lines, ...$accountLines);
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'receipt.save_details');
            foreach ($guides as $guide) {
                $lines[] = '';
                $lines[] = '📘 '.$guide['title'];
                $lines[] = $guide['url'];
            }
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'receipt.login_help');
            $lines[] = $this->locale->translate($customer, 'receipt.thanks');
        } else {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'receipt.recorded');
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'receipt.support_help');
            $lines[] = $this->locale->translate($customer, 'receipt.thanks');
        }

        $units = $this->unitsFromLines($lines, $customer, $guides, withReplyMarkup: true);

        return $this->rendered($units, $guides);
    }

    /**
     * Send exactly one ordered delivery unit. Claiming and finalization stay
     * in TelegramOutboundEffectLedgerProtocol so a transport ambiguity is recorded
     * against this unit only.
     *
     * @return array<string, mixed>
     */
    public function sendEffect(Order $order, TelegramOutboundEffect $effect): array
    {
        if (blank(config('services.telegram.token'))) {
            throw new TelegramOutboundPreSendException('Telegram receipt delivery is not configured.');
        }

        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $customer = $order->customer;
        if (! $customer instanceof Customer || $customer->telegram_id === null) {
            throw new TelegramOutboundPreSendException('Telegram receipt target is missing.');
        }

        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $isLegacy = $effect->telegram_method !== 'sendPhoto'
            && $effect->template_version !== TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE;
        if ($isLegacy) {
            $rendered = $this->renderLegacy($order);
            $index = max(0, (int) $effect->sequence - 1);
            $unit = $rendered['units'][$index] ?? null;
            if (! $unit instanceof TelegramMessageUnit) {
                throw new TelegramOutboundPreSendException('Legacy Telegram receipt unit is no longer renderable.');
            }

            return $this->bot->sendMessage(
                $customer->telegram_id,
                $unit->text(),
                $unit->replyMarkup(),
                $unit->options(),
            );
        }

        $role = (string) ($metadata['unit_role'] ?? ((int) $effect->sequence === 1 ? 'invoice' : 'product_detail'));
        if ($role === 'invoice') {
            $unit = $this->renderInvoiceUnit($order);

            return $this->bot->sendPhoto(
                $customer->telegram_id,
                $unit->photoContents(),
                $unit->filename(),
                $unit->text() !== '' ? $unit->text() : null,
                $unit->replyMarkup(),
                $unit->options(),
            );
        }

        $detail = $this->renderProductDetail($order, $customer);
        $index = max(0, (int) $effect->sequence - 2);
        $unit = $detail['units'][$index] ?? null;
        if (! $unit instanceof TelegramMessageUnit) {
            throw new TelegramOutboundPreSendException('Telegram Product Detail unit is no longer renderable.');
        }

        return $this->bot->sendMessage(
            $customer->telegram_id,
            $unit->text(),
            $unit->replyMarkup(),
            $unit->options(),
        );
    }

    /**
     * @param  list<array{title: string, url: string}>  $guides
     * @return array{chunks: list<string>, units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    private function renderStructuredReceipt(
        Customer $customer,
        Order $order,
        CredentialDelivery $delivery,
        array $guides,
    ): array {
        $headerLines = [
            $this->locale->translate($customer, 'receipt.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'payment.invoice', ['invoice' => $order->invoice_number]),
            '',
            $this->locale->translate($customer, 'receipt.products'),
        ];
        foreach ($order->orderItems as $item) {
            $headerLines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $headerLines[] = '';
        $headerLines[] = $this->locale->translate($customer, 'receipt.account_details');
        $headerLines[] = '━━━━━━━━━━━━━━';

        $footerLines = [
            $this->locale->translate($customer, 'receipt.save_details'),
        ];
        foreach ($guides as $guide) {
            $footerLines[] = '';
            $footerLines[] = '📘 '.$guide['title'];
            $footerLines[] = $guide['url'];
        }
        $footerLines[] = '';
        $footerLines[] = $this->locale->translate($customer, 'receipt.login_help');
        $footerLines[] = $this->locale->translate($customer, 'receipt.thanks');

        $units = [
            ...$this->unitsFromLines($headerLines),
            ...$this->credentialRenderer->accountUnits(
                $customer,
                $delivery,
                'receipt.otp_button',
                'receipt.delivery_note',
            ),
            ...$this->unitsFromLines($footerLines, $customer, $guides, withReplyMarkup: true),
        ];

        return $this->rendered($units, $guides);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{title: string, url: string}>  $guides
     * @return list<TelegramMessageUnit>
     */
    private function unitsFromLines(
        array $lines,
        ?Customer $customer = null,
        array $guides = [],
        bool $withReplyMarkup = false,
    ): array {
        $chunks = $this->messageChunker->fromLines($lines);
        $units = [];
        foreach ($chunks as $index => $chunk) {
            $isFinal = $index === array_key_last($chunks);
            $replyMarkup = $withReplyMarkup && $isFinal && $customer instanceof Customer
                ? $this->receiptReplyMarkup($customer, $guides)
                : [];
            $units[] = new TelegramMessageUnit(
                text: $chunk,
                entities: $this->credentialFormatter->codeEntities($chunk),
                replyMarkup: $replyMarkup,
            );
        }

        return $units;
    }

    /**
     * @param  list<TelegramMessageUnit>  $units
     * @param  list<array{title: string, url: string}>  $guides
     * @return array{chunks: list<string>, units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    private function rendered(array $units, array $guides): array
    {
        return [
            'chunks' => array_map(static fn (TelegramMessageUnit $unit): string => $unit->text(), $units),
            'units' => $units,
            'guides' => $guides,
        ];
    }

    private function hasStructuredAccount(CredentialDelivery $delivery): bool
    {
        foreach ($delivery->groups() as $group) {
            foreach ($group->accounts() as $account) {
                if ($account->isStructured()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function canonicalPaidPayment(Order $order): ?Payment
    {
        return $order->payments
            ->filter(fn ($payment): bool => $payment instanceof Payment
                && (string) $payment->order_id === (string) $order->getKey()
                && $payment->status === PaymentStatus::Paid
                && $payment->paid_at !== null)
            ->sortBy([
                ['paid_at', 'asc'],
                ['id', 'asc'],
            ])
            ->first();
    }

    private function formatPaidAmount(Order $order, ?Payment $payment): string
    {
        $currency = strtoupper(trim((string) ($payment?->currency ?: $order->currency ?: 'IDR')));
        $amount = $payment?->provider_total_payment !== null
            ? (int) $payment->provider_total_payment
            : (int) $order->total_amount;

        if ($currency === 'USDT') {
            return number_format($amount / 1_000_000, 6, '.', '').' USDT';
        }

        if ($currency === 'IDR') {
            return $this->locale->idr(max(0, $amount));
        }

        return number_format(max(0, $amount), 0, ',', '.').' '.$currency;
    }

    private function accountSecretLines(Customer $customer, CredentialDelivery $delivery): array
    {
        $lines = [];
        foreach ($delivery->groups() as $group) {
            $lines[] = $group->productName();
            foreach ($group->accounts() as $account) {
                $lines[] = $account->number().'. '.$account->text();

                if ($account->isStructured()
                    && $account->tutorial() !== null
                    && $account->tutorial() !== $group->defaultTutorial()) {
                    $lines[] = $this->locale->translate($customer, 'receipt.delivery_note');
                    $lines[] = $account->tutorial();
                }
            }
            if ($group->defaultTutorial() !== null) {
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'receipt.delivery_note');
                $lines[] = $group->defaultTutorial();
            }
            $lines[] = '';
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** @param  list<array{title: string, url: string}>  $guides */
    private function receiptReplyMarkup(Customer $customer, array $guides): array
    {
        $rows = collect($guides)
            ->map(fn (array $guide): array => [[
                'text' => $guide['title'],
                'url' => $guide['url'],
            ]])
            ->all();

        $supportUrl = $this->supportContact->resolve()['url'];
        if ($supportUrl !== '') {
            $rows[] = [[
                'text' => $this->locale->translate($customer, 'receipt.support_button'),
                'url' => $supportUrl,
            ]];
        }

        return [
            'inline_keyboard' => $rows,
        ];
    }
}
