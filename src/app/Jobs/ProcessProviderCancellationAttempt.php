<?php

namespace App\Jobs;

use App\Services\ProviderCancellationAttempts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessProviderCancellationAttempt implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $attemptId) {}

    public function uniqueId(): string
    {
        return 'provider-cancellation:'.$this->attemptId;
    }

    public function handle(ProviderCancellationAttempts $attempts): void
    {
        $attempts->process($this->attemptId);
    }

    public function failed(Throwable $exception): void
    {
        app(ProviderCancellationAttempts::class)->markTerminalFailure($this->attemptId, $exception);
    }
}
