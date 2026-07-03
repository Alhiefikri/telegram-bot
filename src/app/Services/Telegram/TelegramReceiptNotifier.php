<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramReceiptNotifier
{
    public function __construct(private TelegramBotClient $bot) {}

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
                if ($locked->payment_status !== PaymentStatus::Paid || (! $force && $locked->telegram_receipt_sent_at !== null)) {
                    return null;
                }

                return $locked;
            });

            if (! $claimed) {
                return;
            }

            $claimed->loadMissing(['customer', 'orderItems.stockItems']);
            if (! $claimed->customer?->telegram_id) {
                return;
            }

            $lines = [
                '✅ Pembayaran diterima',
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$claimed->invoice_number,
                '',
                'Produk pesanan kamu:',
            ];
            foreach ($claimed->orderItems as $item) {
                $lines[] = '- '.$item->product_name.' x '.$item->quantity;
            }
            $accountLines = $this->accountSecretLines($claimed);
            if ($accountLines !== []) {
                $lines[] = '';
                $lines[] = '🔐 Data akun';
                $lines[] = '━━━━━━━━━━━━━━';
                array_push($lines, ...$accountLines);
                $lines[] = '';
                $lines[] = '⚠️ Simpan data ini baik-baik dan jangan dibagikan ke orang lain.';
                $lines[] = '';
                $lines[] = 'Bingung cara masuk dan amankan akunnya?';
                $lines[] = 'Panduan: '.route('storefront.login-guide');
                $lines[] = '';
                $lines[] = 'Kalau ada kendala login, hubungi /support dan sertakan invoice ini.';
                $lines[] = 'Terima kasih sudah belanja di LHIE STORE 🌙';
            } else {
                $lines[] = '';
                $lines[] = 'Pesanan kamu sudah tercatat. Simpan pesan ini sebagai bukti pembelian.';
                $lines[] = '';
                $lines[] = 'Kalau butuh bantuan, hubungi /support dan sertakan invoice ini.';
                $lines[] = 'Terima kasih sudah belanja di LHIE STORE 🌙';
            }

            $this->bot->sendMessage($claimed->customer->telegram_id, implode("\n", $lines), $this->receiptReplyMarkup());
            $receiptSentAt = Order::whereKey($claimed->id);
            if (! $force) {
                $receiptSentAt->whereNull('telegram_receipt_sent_at');
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
            $lines[] = '';
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private function receiptReplyMarkup(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => 'Panduan Login & Keamanan', 'url' => route('storefront.login-guide')],
            ]],
        ];
    }
}
