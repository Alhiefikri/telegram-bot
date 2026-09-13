<?php

namespace App\Jobs;

use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\BalanceTopUpChannelProofDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PublishBalanceTopUpChannelProof implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $topUpId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null
            ? 'balance-top-up-channel-proof:'.$this->topUpId
            : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(
        BalanceTopUpChannelProofDeliveryPipeline $pipeline,
        TelegramOutboundEffectService $outboundEffects,
    ): void {
        $effect = $this->effectId === null
            ? $outboundEffects->forBalanceTopUpChannelProof($this->topUpId)
            : TelegramOutboundEffect::query()
                ->whereKey($this->effectId)
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $this->topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_CHANNEL_PROOF)
                ->firstOrFail();

        if ($effect !== null) {
            $pipeline->deliver($effect);
        }
    }
}
