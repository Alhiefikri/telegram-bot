<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\CustomerPendingOrderCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileCustomerCancellationPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public string $queue = 'payments';

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $paymentId) {}

    public function handle(CustomerPendingOrderCancellation $cancellation): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null) {
            $cancellation->reconcile($payment);
        }
    }
}
