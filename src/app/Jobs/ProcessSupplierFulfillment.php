<?php

namespace App\Jobs;

use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessSupplierFulfillment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $fulfillmentId)
    {
        $this->onConnection('supplier')->onQueue('suppliers');
    }

    public function handle(SupplierFulfillmentService $fulfillment): void
    {
        $fulfillment->process($this->fulfillmentId);
    }
}
