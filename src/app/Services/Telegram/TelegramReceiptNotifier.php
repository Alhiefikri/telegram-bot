<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramReceiptNotifier
{
    public function __construct(private TelegramBotClient $bot, private TelegramLocale $locale) {}

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

                if (! $force && ($locked->telegram_receipt_sent_at !== null || $locked->telegram_receipt_send_started_at !== null)) {
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
                return;
            }

            $lines = [
                $this->locale->text($claimed->customer, '✅ Pembayaran diterima', '✅ Payment received'),
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$claimed->invoice_number,
                '',
                $this->locale->text($claimed->customer, 'Produk pesanan kamu:', 'Your ordered products:'),
            ];
            foreach ($claimed->orderItems as $item) {
                $lines[] = '- '.$item->product_name.' x '.$item->quantity;
            }
            $accountLines = $this->accountSecretLines($claimed);
            if ($accountLines !== []) {
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, '🔐 Data akun', '🔐 Account details');
                $lines[] = '━━━━━━━━━━━━━━';
                array_push($lines, ...$accountLines);
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, '⚠️ Simpan data ini baik-baik dan jangan dibagikan ke orang lain.', '⚠️ Save these details securely and do not share them with anyone.');
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, 'Bingung cara masuk dan amankan akunnya?', 'Need help signing in and securing the account?');
                $lines[] = $this->locale->text($claimed->customer, 'Panduan: ', 'Guide: ').route('storefront.login-guide');
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, 'Kalau ada kendala login, hubungi /support dan sertakan invoice ini.', 'If you have a login problem, contact /support and include this invoice.');
                $lines[] = $this->locale->text($claimed->customer, 'Terima kasih sudah belanja di LHIE STORE 🌙', 'Thank you for shopping at LHIE STORE 🌙');
            } else {
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, 'Pesanan kamu sudah tercatat. Simpan pesan ini sebagai bukti pembelian.', 'Your order has been recorded. Save this message as proof of purchase.');
                $lines[] = '';
                $lines[] = $this->locale->text($claimed->customer, 'Kalau butuh bantuan, hubungi /support dan sertakan invoice ini.', 'If you need help, contact /support and include this invoice.');
                $lines[] = $this->locale->text($claimed->customer, 'Terima kasih sudah belanja di LHIE STORE 🌙', 'Thank you for shopping at LHIE STORE 🌙');
            }

            try {
                $this->bot->sendMessage($claimed->customer->telegram_id, implode("\n", $lines), $this->receiptReplyMarkup($claimed->customer));
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
            $receiptSentAt->update(['telegram_receipt_sent_at' => now()]);
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
                $lines[] = ($index + 1).'. '.$stockItem->secret;
            }
            $deliveryNote = trim((string) ($item->delivery_note_snapshotted ? $item->delivery_note : $item->product?->delivery_note));
            if ($deliveryNote !== '') {
                $lines[] = '';
                $lines[] = $this->locale->text($order->customer, '📌 Catatan pengiriman:', '📌 Delivery note:');
                $lines[] = $deliveryNote;
            }
            $lines[] = '';
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private function receiptReplyMarkup(Customer $customer): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => $this->locale->text($customer, 'Panduan Login & Keamanan', 'Login & Security Guide'), 'url' => route('storefront.login-guide')],
            ]],
        ];
    }
}
