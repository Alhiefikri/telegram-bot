<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryPresentation;

class TelegramReceiptNotifier
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private CredentialDeliveryFormatter $credentialFormatter,
        private CredentialDeliveryPresentation $credentialPresentation,
        private TelegramMessageChunker $messageChunker,
        private TelegramSupportContact $supportContact,
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
        while ($effect !== null) {
            $pipeline->deliver($effect, $this, dispatchNext: false, throwOnAmbiguous: true);
            $effect = TelegramOutboundEffect::query()
                ->where('effect_group_key', $effect->effect_group_key)
                ->where('sequence', '>', $effect->sequence)
                ->whereIn('state', ['pending', 'failed'])
                ->orderBy('sequence')
                ->get()
                ->first(fn (TelegramOutboundEffect $candidate): bool => app(TelegramOutboundEffectLedgerProtocol::class)->isDispatchable($candidate));
        }
    }

    /**
     * Render a receipt in memory. The returned credential-bearing strings are
     * deliberately never persisted in the outbound effect ledger.
     *
     * @return array{chunks: list<string>, guides: list<array{title: string, url: string}>}
     */
    public function render(Order $order): array
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);

        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            return ['chunks' => [''], 'guides' => []];
        }

        $delivery = $this->credentialPresentation->forCustomer($customer, $order);
        $guides = $delivery->usageGuides();
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

        return [
            'chunks' => $this->messageChunker->fromLines($lines),
            'guides' => $guides,
        ];
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

        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);
        $customer = $order->customer;
        if (! $customer instanceof Customer || $customer->telegram_id === null) {
            throw new TelegramOutboundPreSendException('Telegram receipt target is missing.');
        }

        $rendered = $this->render($order);
        $index = max(0, (int) $effect->sequence - 1);
        $chunk = $rendered['chunks'][$index] ?? null;
        if (! is_string($chunk)) {
            throw new TelegramOutboundPreSendException('Telegram receipt delivery unit is no longer renderable.');
        }

        $isFinal = $index === array_key_last($rendered['chunks']);

        return $this->bot->sendMessage(
            $customer->telegram_id,
            $chunk,
            $isFinal ? $this->receiptReplyMarkup($customer, $rendered['guides']) : [],
            $this->credentialOptions($chunk),
        );
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

    private function credentialOptions(string $text): array
    {
        $entities = $this->credentialFormatter->codeEntities($text);

        return $entities === [] ? [] : ['entities' => $entities];
    }
}
