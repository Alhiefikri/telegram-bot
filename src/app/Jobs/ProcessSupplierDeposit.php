<?php

namespace App\Jobs;

use App\Services\Suppliers\SupplierDepositService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessSupplierDeposit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $depositId)
    {
        $this->onConnection('supplier')->onQueue('supplier-claims');
    }

    public function handle(SupplierDepositService $deposits): void
    {
        $deposits->process($this->depositId);
    }
}
