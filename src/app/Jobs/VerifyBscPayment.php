<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
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
        $payment = Payment::query()->with(['order', 'bscDetail'])->find($this->paymentId);
        if (! $payment) {
            return;
        }

        if ($payment->status === PaymentStatus::Pending
            && $payment->order?->order_status === OrderStatus::PendingPayment) {
            $verification->verify($payment, null, $this->submissionVersion);

            return;
        }

        $detail = $payment->bscDetail;
        $terminalOrder = in_array($payment->order?->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);
        $reviewablePayment = in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true);
        if (! $terminalOrder
            || ! $reviewablePayment
            || $detail?->verification_mode !== PaymentBscDetail::VERIFICATION_MODE_TRANSACTION_HASH
            || ! filled($detail->current_tx_hash)) {
            return;
        }

        $verification->verify(
            $payment,
            $detail->current_tx_hash,
            (int) $detail->tx_submission_version,
        );
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
