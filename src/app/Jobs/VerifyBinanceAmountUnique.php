<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Telegram\TelegramBinanceVerifyIntents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class VerifyBinanceAmountUnique implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public int $timeout = 120;

    public function __construct(public readonly string $paymentId) {}

    public function uniqueId(): string
    {
        return $this->paymentId;
    }

    public function handle(TelegramBinanceVerifyIntents $intents): void
    {
        $intents->verifyAmountUnique($this->paymentId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        try {
            $payment = Payment::query()->with('order.customer')->find($this->paymentId);
            if (! $payment || $payment->status !== PaymentStatus::Pending) {
                return;
            }

            $result = app(BinancePaymentVerification::class)->recordQueueFailure($payment);

            $payment->refresh();
            $order = $payment->order;
            $customer = $order?->customer;
            if (! $order || ! $customer) {
                return;
            }

            app(TelegramBinanceVerifyIntents::class)->respondToResult(
                $customer,
                (string) $customer->telegram_id,
                $order,
                $payment,
                $result,
                allowRetryButtons: false,
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
