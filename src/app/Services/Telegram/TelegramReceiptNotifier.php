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
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\UsdtBalance\UsdtMicroAmount;

class TelegramReceiptNotifier
{
    public const PHOTO_CAPTION_LIMIT = 1024;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private CredentialDeliveryFormatter $credentialFormatter,
        private CredentialDeliveryPresentation $credentialPresentation,
        private TelegramCredentialDeliveryRenderer $credentialRenderer,
        private TelegramMessageChunker $messageChunker,
        private TelegramSupportContact $supportContact,
        private PaidInvoiceRenderer $invoiceRenderer,
        private PaidInvoiceOrderPresentationAdapter $invoicePresentation,
        private TelegramOutboundEffectLedgerProtocol $ledger,
        private TelegramUiIcons $uiIcons,
        private TelegramProductNameRenderer $productNames,
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

            $effect = $this->ledger->nextReceiptEffect(
                $effect->effect_group_key,
                (int) $effect->sequence,
            );
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
     * Render the complete buyer receipt plan in memory.
     *
     * @return array{chunks: list<string>, units: list<TelegramMessageUnit>, guides: list<array{title: string, url: string}>}
     */
    public function render(Order $order): array
    {
        $plan = $this->receiptPlan($order);
        $caption = $plan->photoUnit(1);
        $invoice = $this->renderInvoiceUnit($order);
        $photo = new TelegramPhotoUnit(
            photoContents: $invoice->photoContents(),
            filename: $invoice->filename(),
            caption: $caption->text(),
            captionEntities: $caption->entities(),
            replyMarkup: $caption->replyMarkup(),
        );

        $units = [$photo, ...array_slice($plan->photoUnits(), 1)];

        return $this->rendered($units, $plan->guides());
    }

    /**
     * Build one transient plan for a single render attempt. No returned value
     * may cross a queue, cache, log, or outbound-effect persistence boundary.
     */
    public function receiptPlan(Order $order): TelegramReceiptPlan
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            throw new TelegramOutboundPreSendException('Telegram receipt target is missing.');
        }

        try {
            $delivery = $this->credentialPresentation->forCustomer($customer, $order);
            $stockItemCount = $order->orderItems->sum(
                static fn ($item): int => $item->stockItems->count(),
            );
            if ($stockItemCount > 0 && $delivery->accountCount() !== $stockItemCount) {
                throw new \InvalidArgumentException('Telegram receipt credential projection is incomplete.');
            }

            $blocks = $this->receiptHeaderBlocks($order, $customer);
            if ($delivery->isEmpty()) {
                $blocks[] = new TelegramMessageUnit(
                    "\n".$this->locale->translate($customer, 'receipt.no_credentials'),
                );
            } else {
                array_push($blocks, ...$this->credentialRenderer->receiptBlocks($customer, $delivery));
            }

            $footerLines = [
                $this->locale->translate($customer, 'receipt.save_details'),
            ];
            if (! $delivery->isEmpty()) {
                $footerLines[] = $this->locale->translate($customer, 'receipt.copy_guidance');
            }
            $footerLines[] = $this->locale->translate($customer, 'receipt.thanks');
            $blocks[] = new TelegramMessageUnit(
                "\n".implode("\n\n", $footerLines),
                replyMarkup: $this->receiptReplyMarkup($customer, []),
            );

            return new TelegramReceiptPlan(
                photoUnits: $this->packReceiptBlocks(
                    $blocks,
                    TelegramReceiptPlan::PHOTO_CAPTION_LIMIT,
                    photoFirst: true,
                ),
                textUnits: $this->packReceiptBlocks(
                    $blocks,
                    TelegramReceiptPlan::MESSAGE_TEXT_LIMIT,
                    photoFirst: false,
                ),
                productCount: $this->credentialRenderer->receiptProductCount($delivery),
                accountCount: $delivery->accountCount(),
                guides: $delivery->usageGuides(),
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof TelegramOutboundPreSendException) {
                throw $exception;
            }

            throw new TelegramOutboundPreSendException(
                'Telegram receipt is no longer renderable.',
                $exception,
            );
        }
    }

    public function photoCaptionFits(Order $order): bool
    {
        try {
            return $this->receiptPlan($order)->photoUnitCount() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function renderPhotoCaption(Order $order): TelegramMessageUnit
    {
        $plan = $this->receiptPlan($order);
        if ($plan->photoUnitCount() !== 1) {
            throw new TelegramOutboundPreSendException(
                'Telegram buyer receipt caption is no longer renderable within the photo limit.',
            );
        }

        return $plan->photoUnit(1);
    }

    public function renderReceiptPhotoUnit(Order $order): TelegramPhotoUnit
    {
        $caption = $this->renderPhotoCaption($order);
        $invoice = $this->renderInvoiceUnit($order);

        return new TelegramPhotoUnit(
            photoContents: $invoice->photoContents(),
            filename: $invoice->filename(),
            caption: $caption->text(),
            captionEntities: $caption->entities(),
            replyMarkup: $caption->replyMarkup(),
        );
    }

    /**
     * Count new receipt units without rendering or retaining PNG bytes.
     */
    public function deliveryUnitCount(Order $order): int
    {
        return $this->receiptPlan($order)->photoUnitCount();
    }

    /**
     * Render only the invoice unit for the current send attempt.
     */
    public function renderInvoiceUnit(Order $order): TelegramPhotoUnit
    {
        try {
            $presentation = $this->invoicePresentation->present($order);
            $result = $this->invoiceRenderer->render($presentation);
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
            $summaryLines[] = '- '.$this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).' x '.$item->quantity;
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
            implode("\n\n", [
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
            $lines[] = '- '.$this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).' x '.$item->quantity;
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
                $lines[] = $this->uiIcons->renderLegacyIcons('📘 ').$guide['title'];
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

        if ($effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION) {
            $plan = $this->receiptPlan($order);
            $this->assertReceiptPlanMatchesEffect($plan, $effect, textFallback: false);
            $unit = $plan->photoUnit((int) $effect->sequence);

            if ((int) $effect->sequence === 1) {
                $invoice = $this->renderInvoiceUnit($order);
                $options = ['protect_content' => true];
                if ($unit->entities() !== []) {
                    $options['caption_entities'] = $unit->entities();
                }

                return $this->bot->sendPhoto(
                    $customer->telegram_id,
                    $invoice->photoContents(),
                    $invoice->filename(),
                    $unit->text() !== '' ? $unit->text() : null,
                    $unit->replyMarkup(),
                    $options,
                );
            }

            return $this->bot->sendMessage(
                $customer->telegram_id,
                $unit->text(),
                $unit->replyMarkup(),
                array_merge(['protect_content' => true], $unit->options()),
            );
        }

        if ($effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK) {
            $plan = $this->receiptPlan($order);
            $this->assertReceiptPlanMatchesEffect($plan, $effect, textFallback: true);
            $unit = $plan->textUnit((int) $effect->sequence);

            return $this->bot->sendMessage(
                $customer->telegram_id,
                $unit->text(),
                $unit->replyMarkup(),
                array_merge(['protect_content' => true], $unit->options()),
            );
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
            $headerLines[] = '- '.$this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).' x '.$item->quantity;
        }
        $headerLines[] = '';
        $headerLines[] = $this->locale->translate($customer, 'receipt.account_details');
        $headerLines[] = '━━━━━━━━━━━━━━';

        $footerLines = [
            $this->locale->translate($customer, 'receipt.save_details'),
        ];
        foreach ($guides as $guide) {
            $footerLines[] = '';
            $footerLines[] = $this->uiIcons->renderLegacyIcons('📘 ').$guide['title'];
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
     * @return list<TelegramMessageUnit>
     */
    private function receiptHeaderBlocks(
        Order $order,
        Customer $customer,
    ): array {
        $payment = $this->canonicalPaidPayment($order);
        $headerLines = [
            $this->locale->translate($customer, 'receipt.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'receipt.order_id', [
                'order' => $order->invoice_number ?: $order->getKey(),
            ]),
        ];
        $productLines = [
            $this->locale->translate($customer, 'receipt.products'),
        ];
        foreach ($order->orderItems->sortBy('id')->values() as $item) {
            $productLines[] = '- '.$this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).' x '.$item->quantity;
        }
        $paymentLines = [
            $this->locale->translate($customer, 'receipt.payment_method', [
                'method' => $payment instanceof Payment
                    ? $this->locale->paymentMethod($customer, $payment)
                    : $this->locale->translate($customer, 'payment.method.unknown'),
            ]),
            '',
            $this->locale->translate($customer, 'receipt.paid_at', [
                'date' => $this->locale->formatDate($customer, $payment?->paid_at ?? $order->updated_at ?? now()),
            ]),
            '',
            $this->locale->translate($customer, 'receipt.total_quantity', [
                'quantity' => $order->orderItems->sum(fn ($item): int => max(0, (int) $item->quantity)),
            ]),
            $this->locale->translate($customer, 'receipt.amount_paid', [
                'amount' => $this->formatPaidAmount($order, $payment),
            ]),
        ];
        $sections = [
            $headerLines,
            $productLines,
            $paymentLines,
            [$this->locale->translate($customer, 'receipt.product_detail')],
        ];
        $blocks = [];
        foreach ($sections as $sectionIndex => $lines) {
            foreach ($this->messageChunker->fromLines($lines, TelegramReceiptPlan::MESSAGE_TEXT_LIMIT) as $chunkIndex => $chunk) {
                if ($sectionIndex > 0 && $chunkIndex === 0) {
                    $chunk = "\n".$chunk;
                }
                $blocks[] = new TelegramMessageUnit($chunk);
            }
        }

        return $blocks;
    }

    /**
     * @param  list<TelegramMessageUnit>  $blocks
     * @return list<TelegramMessageUnit>
     */
    private function packReceiptBlocks(array $blocks, int $firstLimit, bool $photoFirst): array
    {
        $chunks = [];
        $current = [];
        $currentLength = 0;
        $first = $photoFirst;

        foreach ($blocks as $block) {
            $blockLength = $block->characterLength();
            if ($blockLength < 1 || $blockLength > TelegramReceiptPlan::MESSAGE_TEXT_LIMIT) {
                throw new \InvalidArgumentException('Telegram receipt block exceeds the message limit.');
            }

            $limit = $first ? $firstLimit : TelegramReceiptPlan::MESSAGE_TEXT_LIMIT;
            $candidateLength = $current === []
                ? $blockLength
                : $currentLength + 1 + $blockLength;
            if ($current !== [] && $candidateLength > $limit) {
                $chunks[] = $this->combineReceiptBlocks($current);
                $current = [$block];
                $currentLength = $blockLength;
                $first = false;

                continue;
            }

            if ($current === [] && $first && $blockLength > $limit) {
                throw new \InvalidArgumentException('Telegram receipt header exceeds the photo caption limit.');
            }

            $current[] = $block;
            $currentLength = $candidateLength;
        }

        if ($current !== []) {
            $chunks[] = $this->combineReceiptBlocks($current);
        }

        return $chunks;
    }

    /** @param list<TelegramMessageUnit> $blocks */
    private function combineReceiptBlocks(array $blocks): TelegramMessageUnit
    {
        $text = '';
        $entities = [];
        $rows = [];
        $containsCredentials = false;

        foreach ($blocks as $block) {
            $prefix = $text === '' ? '' : $text."\n";
            $entityOffset = $this->utf16Length($prefix);
            $text = $prefix.$block->text();
            $containsCredentials = $containsCredentials || $block->containsCredentials();

            foreach ($block->entities() as $entity) {
                $entities[] = [
                    ...$entity,
                    'offset' => $entityOffset + $entity['offset'],
                ];
            }

            $inlineKeyboard = $block->replyMarkup()['inline_keyboard'] ?? [];
            if (is_array($inlineKeyboard)) {
                array_push($rows, ...$inlineKeyboard);
            }
        }

        return new TelegramMessageUnit(
            text: $text,
            entities: $entities,
            replyMarkup: $rows === [] ? [] : ['inline_keyboard' => $rows],
            containsCredentials: $containsCredentials,
        );
    }

    private function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
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

    private function assertReceiptPlanMatchesEffect(
        TelegramReceiptPlan $plan,
        TelegramOutboundEffect $effect,
        bool $textFallback,
    ): void {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $expectedCount = $textFallback ? $plan->textUnitCount() : $plan->photoUnitCount();
        if ((int) ($metadata['receipt_chunk_count'] ?? 0) !== $expectedCount) {
            throw new TelegramOutboundPreSendException('Telegram receipt is no longer renderable.');
        }
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
            return UsdtMicroAmount::formatUnits((int) ($payment?->amount ?? 0)).' USDT';
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
            $lines[] = $this->productNames->render(
                $group->productName(),
                $group->productIcon(),
                $group->productCustomEmojiId(),
            );
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
