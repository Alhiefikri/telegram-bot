<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramBuyerCheckoutInstructionCleaner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteBuyerBinanceCheckoutInstruction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $orderId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramBuyerCheckoutInstructionCleaner $cleaner): void
    {
        $cleaner->deleteBinanceCheckoutInstruction(Order::findOrFail($this->orderId));
    }
}
