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
        private TelegramUiIcons $uiIcons,
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
            $this->uiIcons->renderLegacyIcons('⚠️ Verifikasi Binance butuh perhatian'),
            '━━━━━━━━━━━━━━',
            $this->uiIcons->renderLegacyIcons('🧾 Invoice: ').$order->invoice_number,
            $this->uiIcons->renderLegacyIcons('💵 Tagihan: ').$this->formatUsdt((int) $payment->amount).' USDT',
            $this->uiIcons->renderLegacyIcons('📌 Hasil: ').$result->outcome->value,
        ];

        if ($submittedOrderId !== null) {
            $lines[] = $this->uiIcons->renderLegacyIcons('🔢 Order ID dikirim buyer: ').$this->maskIdentifier($submittedOrderId);
        }

        if ($result->transaction !== null) {
            $transaction = $result->transaction;
            $reference = $transaction->orderId() !== '' ? 'Order ID '.$this->maskIdentifier($transaction->orderId()).' · ' : '';
            $lines[] = $this->uiIcons->renderLegacyIcons('🔎 Transaksi ditemukan: ').$this->formatUsdt(abs($transaction->amountMicro())).' '.$transaction->currency()
                .' · '.$reference.'Transaction ID '.$this->maskIdentifier($transaction->transactionId());
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

    private function maskIdentifier(string $value): string
    {
        $value = trim($value);
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        if (str_contains($value, '•')) {
            return $value;
        }

        return substr($value, 0, 4).'••••'.substr($value, -4);
    }
}
