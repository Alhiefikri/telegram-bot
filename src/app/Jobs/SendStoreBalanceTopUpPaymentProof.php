<?php

namespace App\Jobs;

use App\Services\Telegram\BalanceTopUpPaymentProofDeliveryPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendStoreBalanceTopUpPaymentProof implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $topUpId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null
            ? 'balance-top-up-proof:'.$this->topUpId
            : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(?BalanceTopUpPaymentProofDeliveryPipeline $delivery = null): void
    {
        ($delivery ??= app(BalanceTopUpPaymentProofDeliveryPipeline::class))->deliver(
            $this->topUpId,
            $this->effectId,
        );
    }
}
