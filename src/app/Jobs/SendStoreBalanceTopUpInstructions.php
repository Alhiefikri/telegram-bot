<?php

namespace App\Jobs;

use App\Services\Telegram\BalanceTopUpInstructionDeliveryPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The Outbound Effect ledger is the idempotency boundary for this job.
 *
 * Do not add ShouldBeUnique here: Laravel can acquire a unique lock before
 * the queue connection accepts the payload, leaving a stale lock when that
 * enqueue fails and suppressing the customer's recovery dispatch.
 */
final class SendStoreBalanceTopUpInstructions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $topUpId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(?BalanceTopUpInstructionDeliveryPipeline $delivery = null): void
    {
        ($delivery ??= app(BalanceTopUpInstructionDeliveryPipeline::class))->deliver(
            $this->topUpId,
            $this->effectId,
        );
    }
}
