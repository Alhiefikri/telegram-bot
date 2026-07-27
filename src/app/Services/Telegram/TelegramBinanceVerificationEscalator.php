<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceVerificationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBinanceVerificationEscalator
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
    ) {}

    public function notify(Order $order, Payment $payment, BinanceVerificationResult $result, ?string $submittedOrderId = null): void
    {
        $adminChatId = $this->adminAccess->adminChatId();
        if ($adminChatId === null) {
            Log::warning('Binance verification escalation has no admin chat configured.', [
                'order_id' => $order->id,
                'outcome' => $result->outcome->value,
            ]);

            return;
        }

        $lines = [
            '⚠️ Verifikasi Binance butuh perhatian',
            '━━━━━━━━━━━━━━',
            "🧾 Invoice: {$order->invoice_number}",
            '💵 Tagihan: '.$this->formatUsdt((int) $payment->amount).' USDT',
            '📌 Hasil: '.$result->outcome->value,
        ];

        if ($submittedOrderId !== null) {
            $lines[] = '🔢 Order ID dikirim buyer: '.$submittedOrderId;
        }

        if ($result->transaction !== null) {
            $transaction = $result->transaction;
            $reference = $transaction->orderId() !== '' ? 'Order ID '.$transaction->orderId().' · ' : '';
            $lines[] = '🔎 Transaksi ditemukan: '.$this->formatUsdt(abs($transaction->amountMicro())).' '.$transaction->currency()
                .' · '.$reference.'Transaction ID '.$transaction->transactionId();
        }

        $lines[] = '';
        $lines[] = 'Cek riwayat Binance Pay lalu selesaikan lewat dashboard admin.';

        try {
            $this->bot->sendMessage($adminChatId, implode("\n", $lines));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function formatUsdt(int $amountMicro): string
    {
        return rtrim(rtrim(number_format($amountMicro / 1_000_000, 6, '.', ''), '0'), '.');
    }
}
