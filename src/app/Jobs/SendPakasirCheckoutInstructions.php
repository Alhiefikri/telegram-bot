<?php

namespace App\Jobs;

use App\Services\Telegram\CheckoutInstructionDeliveryPipeline;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPakasirCheckoutInstructions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $orderId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null ? $this->orderId : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramCheckoutIntents $checkoutIntents, ?CheckoutInstructionDeliveryPipeline $delivery = null): void
    {
        ($delivery ??= app(CheckoutInstructionDeliveryPipeline::class))->deliverOrder(
            $this->orderId,
            TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
            $this->effectId,
            $checkoutIntents,
        );
    }
}
