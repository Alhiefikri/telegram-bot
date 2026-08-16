<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CredentialDeliveryFormatter;
use App\Services\ProductUsageGuide;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramReceiptNotifier
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private CredentialDeliveryFormatter $credentialFormatter,
        private ProductUsageGuide $usageGuides,
        private TelegramMessageChunker $messageChunker,
        private TelegramSupportContact $supportContact,
    ) {}

    public function send(Order $order, bool $force = false): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $lock = Cache::lock('telegram-receipt:'.$order->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $claimed = DB::transaction(function () use ($order, $force) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->payment_status !== PaymentStatus::Paid) {
                    return null;
                }

                if (! $force && ($locked->telegram_receipt_sent_at !== null || $this->hasActiveClaim($locked->telegram_receipt_send_started_at))) {
                    return null;
                }

                // Claim before Telegram I/O. If the worker dies after Telegram accepts
                // the message but before sent_at is written, retries skip this effect
                // instead of sending duplicate account credentials.
                $locked->forceFill(['telegram_receipt_send_started_at' => now()])->save();

                return $locked->fresh();
            });

            if (! $claimed) {
                return;
            }

            $claimed->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);
            if (! $claimed->customer?->telegram_id) {
                Order::whereKey($claimed->id)
                    ->whereNull('telegram_receipt_sent_at')
                    ->update(['telegram_receipt_send_started_at' => null]);

                return;
            }

            $guides = $this->usageGuides->forOrder($claimed);
            $lines = [
                $this->locale->translate($claimed->customer, 'receipt.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($claimed->customer, 'payment.invoice', ['invoice' => $claimed->invoice_number]),
                '',
                $this->locale->translate($claimed->customer, 'receipt.products'),
            ];
            foreach ($claimed->orderItems as $item) {
                $lines[] = '- '.$item->product_name.' x '.$item->quantity;
            }
            $accountLines = $this->accountSecretLines($claimed);
            if ($accountLines !== []) {
                $lines[] = '';
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.account_details');
                $lines[] = '━━━━━━━━━━━━━━';
                array_push($lines, ...$accountLines);
                $lines[] = '';
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.save_details');
                foreach ($guides as $guide) {
                    $lines[] = '';
                    $lines[] = '📘 '.$guide['title'];
                    $lines[] = $guide['url'];
                }
                $lines[] = '';
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.login_help');
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.thanks');
            } else {
                $lines[] = '';
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.recorded');
                $lines[] = '';
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.support_help');
                $lines[] = $this->locale->translate($claimed->customer, 'receipt.thanks');
            }

            try {
                $chunks = $this->messageChunker->fromLines($lines);
                foreach ($chunks as $index => $chunk) {
                    $this->bot->sendMessage(
                        $claimed->customer->telegram_id,
                        $chunk,
                        $index === array_key_last($chunks) ? $this->receiptReplyMarkup($claimed->customer, $guides) : [],
                        $this->credentialOptions($chunk),
                    );
                }
            } catch (\Throwable $e) {
                Order::whereKey($claimed->id)
                    ->whereNull('telegram_receipt_sent_at')
                    ->update(['telegram_receipt_send_started_at' => null]);

                throw $e;
            }

            $receiptSentAt = Order::whereKey($claimed->id);
            if (! $force) {
                $receiptSentAt->whereNotNull('telegram_receipt_send_started_at')->whereNull('telegram_receipt_sent_at');
            }
            $receiptSentAt->update(['telegram_receipt_sent_at' => now(), 'telegram_receipt_send_started_at' => null]);
            DeleteBuyerCheckoutMessages::dispatch($claimed->id, now()->toISOString())->onQueue('notifications');
        } finally {
            $lock->release();
        }
    }

    private function accountSecretLines(Order $order): array
    {
        $lines = [];
        foreach ($order->orderItems as $item) {
            if ($item->stockItems->isEmpty()) {
                continue;
            }

            $lines[] = $item->product_name;
            foreach ($item->stockItems->values() as $index => $stockItem) {
                $lines[] = ($index + 1).'. '.$this->credentialFormatter->format((string) $stockItem->secret);
            }
            $deliveryNote = trim((string) ($item->delivery_note_snapshotted ? $item->delivery_note : $item->product?->delivery_note));
            if ($deliveryNote !== '') {
                $lines[] = '';
                $lines[] = $this->locale->translate($order->customer, 'receipt.delivery_note');
                $lines[] = $deliveryNote;
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

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
