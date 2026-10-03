<?php

namespace App\Jobs;

use App\Services\Warranty\WarrantyAccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessWarrantyConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $unitId)
    {
        $this->onConnection('supplier')->onQueue('supplier-claims');
    }

    public function handle(WarrantyAccountService $accounts): void
    {
        $accounts->process($this->unitId);
    }
}
