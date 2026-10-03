<?php

namespace App\Jobs;

use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessWarrantyClaim implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $claimId)
    {
        $urgent = WarrantyClaim::query()->whereKey($claimId)->where('state', 'received')->whereNull('dispatched_at')->exists();
        $this->onConnection('supplier')->onQueue($urgent ? 'supplier-refunds' : 'supplier-claims');
    }

    public function handle(WarrantyClaimService $claims): void
    {
        $claims->process($this->claimId);
    }
}
