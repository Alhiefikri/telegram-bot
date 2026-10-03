<?php

namespace App\Jobs;

use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessWarrantyClaim implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $claimId, bool $urgent)
    {
        $this->onConnection('supplier')->onQueue($urgent ? 'supplier-refunds' : 'supplier-claims');
    }

    public function handle(WarrantyClaimService $claims): void
    {
        $claims->process($this->claimId);
    }
}
