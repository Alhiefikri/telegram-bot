<?php

namespace App\Jobs;

use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\StoreBalanceTopUpInstructionCleaner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeleteStoreBalanceTopUpInstruction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $topUpId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return $this->topUpId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(StoreBalanceTopUpInstructionCleaner $cleaner): void
    {
        $cleaner->deleteInstruction(StoreBalanceTopUp::findOrFail($this->topUpId));
    }
}
