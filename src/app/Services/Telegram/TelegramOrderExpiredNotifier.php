<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use Throwable;

class TelegramOrderExpiredNotifier
{
    public function __construct(private readonly TelegramBotClient $bot, private readonly TelegramLocale $locale) {}

    public function send(Order $order): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $claimed = DB::transaction(function () use ($order): ?Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->order_status !== OrderStatus::Expired
                || $locked->telegram_expired_order_notified_at !== null
                || $this->hasActiveClaim($locked->telegram_expired_order_notification_started_at)) {
                return null;
            }

            $locked->forceFill(['telegram_expired_order_notification_started_at' => now()])->save();

            return $locked->fresh(['customer', 'orderItems', 'payments']);
        }, 5);

        if (! $claimed || ! $claimed->customer?->telegram_id) {
            if ($claimed) {
                Order::query()->whereKey($claimed->id)->update(['telegram_expired_order_notification_started_at' => null]);
            }

            return;
        }

        $order = $claimed;

        $binancePayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->status === PaymentStatus::Expired
            && filled($payment->binance_order_id));

        if ($binancePayment !== null) {
            $lines = [
                $this->locale->text($order->customer, '⏳ Verifikasi Binance belum selesai', '⏳ Binance verification did not finish'),
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$order->invoice_number,
                $this->locale->text($order->customer, '💵 Order ID sudah diterima, tetapi transaksi belum bisa dikonfirmasi otomatis.', '💵 Your Order ID was received, but the transaction could not be confirmed automatically.'),
                '',
                $this->locale->text($order->customer, 'Kalau kamu sudah membayar, kirim screenshot bukti transfer sebagai foto dengan caption:', 'If you already paid, send your transfer screenshot as a photo with this caption:'),
                '/payment '.$order->invoice_number,
                '',
                $this->locale->text($order->customer, 'Admin akan mengecek pembayaran terlambat secara manual. Stok tidak dikunci ulang otomatis.', 'The admin will review the late payment manually. Stock is not reserved again automatically.'),
            ];
            $replyMarkup = [];
        } else {
            $lines = [
                $this->locale->text($order->customer, '⏳ Order kedaluwarsa', '⏳ Order expired'),
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$order->invoice_number,
                $this->locale->text($order->customer, '📦 Produk: ', '📦 Products: ').$order->orderItems->map(fn ($item) => $item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : ''))->join(', '),
                '💰 Total: Rp'.number_format($order->total_amount, 0, ',', '.'),
                '',
                $this->locale->text($order->customer, 'Pembayaran tidak selesai sampai batas waktu, jadi order dibatalkan otomatis.', 'Payment was not completed before the deadline, so this order was cancelled automatically.'),
                '',
                $this->locale->text($order->customer, 'Tenang, kamu masih bisa order ulang kapan aja.', 'You can place a new order anytime.'),
            ];
            $buttonText = $order->payments->contains(fn ($payment): bool => strtolower((string) $payment->provider) === PaymentProvider::KLIKQRIS)
                ? $this->locale->text($order->customer, '🔄 Buat QRIS Baru', '🔄 Create New QRIS')
                : $this->locale->text($order->customer, '🛍️ Order Ulang', '🛍️ Order Again');
            $replyMarkup = [
                'inline_keyboard' => [[['text' => $buttonText, 'callback_data' => 'katalog']]],
            ];
        }

        try {
            $this->bot->sendMessage((string) $order->customer->telegram_id, implode("\n", $lines), $replyMarkup);
        } catch (Throwable $exception) {
            Order::query()
                ->whereKey($order->id)
                ->whereNull('telegram_expired_order_notified_at')
                ->update(['telegram_expired_order_notification_started_at' => null]);

            throw $exception;
        }

        Order::query()
            ->whereKey($order->id)
            ->whereNotNull('telegram_expired_order_notification_started_at')
            ->whereNull('telegram_expired_order_notified_at')
            ->update([
                'telegram_expired_order_notification_started_at' => null,
                'telegram_expired_order_notified_at' => now(),
            ]);
    }

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
