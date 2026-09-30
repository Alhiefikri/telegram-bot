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
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramProductNameRenderer $productNames,
    ) {}

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

        $binancePayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->status === PaymentStatus::Expired
            && filled($payment->binance_order_id));

        if ($binancePayment !== null) {
            $lines = [
                $this->locale->translate($order->customer, 'payment.expired.binance_title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($order->customer, 'payment.invoice', ['invoice' => $order->invoice_number]),
                $this->locale->translate($order->customer, 'payment.expired.binance_order_id'),
                '',
                $this->locale->translate($order->customer, 'payment.expired.binance_prompt'),
                '/payment '.$order->invoice_number,
                '',
                $this->locale->translate($order->customer, 'payment.expired.binance_review'),
            ];
            $replyMarkup = [];
        } else {
            $lines = [
                $this->locale->translate($order->customer, 'payment.expired.title'),
                '━━━━━━━━━━━━━━',
                $this->locale->translate($order->customer, 'payment.invoice', ['invoice' => $order->invoice_number]),
                $this->locale->translate($order->customer, 'payment.products', [
                    'items' => $order->orderItems->map(fn ($item) => $this->productNames->render(
                        (string) $item->product_name,
                        $item->product_icon,
                        $item->product_custom_emoji_id,
                    ).($item->quantity > 1 ? ' x'.$item->quantity : ''))->join(', '),
                ]),
                $this->locale->translate($order->customer, 'payment.total', [
                    'amount' => 'Rp'.number_format($order->total_amount, 0, ',', '.'),
                ]),
                '',
                $this->locale->translate($order->customer, 'payment.expired.cancelled'),
                '',
                $this->locale->translate($order->customer, 'payment.expired.reorder'),
            ];
            $buttonKey = $order->payments->contains(fn ($payment): bool => strtolower((string) $payment->provider) === PaymentProvider::KLIKQRIS)
                ? 'payment.action.new_qris'
                : 'payment.action.reorder';
            $replyMarkup = [
                'inline_keyboard' => [[[
                    'text' => $this->locale->translate($order->customer, $buttonKey),
                    'callback_data' => 'katalog',
                ]]],
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
