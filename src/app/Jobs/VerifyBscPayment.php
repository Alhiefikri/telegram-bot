<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Bsc\BscPaymentVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class VerifyBscPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 240;

    public int $timeout = 120;

    public function __construct(
        public readonly string $paymentId,
        public readonly int $submissionVersion = 0,
    ) {}

    public function uniqueId(): string
    {
        return $this->paymentId.':'.$this->submissionVersion;
    }

    public function handle(BscPaymentVerification $verification): void
    {
        $payment = Payment::query()->find($this->paymentId);
        if (! $payment || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        $verification->verify($payment, null, $this->submissionVersion);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        try {
            $payment = Payment::query()->find($this->paymentId);
            if ($payment !== null) {
                app(BscPaymentVerification::class)->recordQueueFailure($payment, $this->submissionVersion);
            }
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
