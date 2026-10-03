<?php

namespace App\Jobs;

use App\Services\Warranty\WarrantyReplacementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessWarrantyReplacement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $claimId)
    {
        $this->onConnection('supplier')->onQueue('suppliers');
    }

    public function handle(WarrantyReplacementService $replacements): void
    {
        $replacements->process($this->claimId);
    }
}
