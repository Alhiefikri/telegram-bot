<?php

namespace App\Jobs;

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

class VerifyBinanceOrderId implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 240;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [15 + random_int(0, 5), 30 + random_int(0, 10), 60 + random_int(0, 15)];
    }

    public function __construct(
        public readonly string $paymentId,
        string $orderId,
        public readonly int $attemptNumber,
        public readonly int $retryNumber = 1,
        public readonly int $submissionVersion = 0,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->paymentId, $this->submissionVersion, $this->attemptNumber, $this->retryNumber]);
    }

    public function handle(TelegramBinanceVerifyIntents $intents): void
    {
        // The submitted identifier is deliberately not serialized into queue
        // or failed-job payloads. The service reloads the current value and
        // validates the submission version before any provider request.
        $intents->retryOrderId($this->paymentId, '', $this->attemptNumber, $this->retryNumber, $this->submissionVersion);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        try {
            $payment = Payment::query()->with('order.customer')->find($this->paymentId);
            if (! $payment) {
                return;
            }

            $result = app(BinancePaymentVerification::class)->recordQueueFailure($payment, $this->submissionVersion, closeGrace: true);

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
                submittedOrderId: (string) $payment->binance_order_id,
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
