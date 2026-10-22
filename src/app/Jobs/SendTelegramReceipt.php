<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramReceipt implements ShouldQueueAfterCommit
{
    use Queueable;

    public const ENQUEUE_FIRST = 'first_enqueue';

    public const ENQUEUE_RETRY = 'retry_enqueue';

    public const ENQUEUE_RECOVERY = 'recovery_enqueue';

    /** @var list<string> */
    public const ENQUEUE_STAGES = [self::ENQUEUE_FIRST, self::ENQUEUE_RETRY, self::ENQUEUE_RECOVERY];

    public const REASON_PAID_COMMIT = 'paid_commit';

    public const REASON_MANUAL_FULFILLMENT = 'manual_fulfillment_completion';

    public const REASON_SUPPLIER_FULFILLMENT = 'supplier_fulfillment_completion';

    public const REASON_EFFECT_DISPATCH = 'effect_dispatch';

    public const REASON_EXPLICIT_RESEND = 'explicit_resend';

    public const REASON_RECOVERY_SWEEP = 'recovery_sweep';

    public function __construct(
        public string $orderId,
        public bool $force = false,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
        public ?string $enqueueStage = null,
        public ?string $enqueueReason = null,
    ) {}

    public function handle(
        TelegramOutboundEffectService $outboundEffects,
        ReceiptDeliveryPipeline $pipeline,
    ): void {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.receipt_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'effect_correlation_id' => DogfoodTiming::correlation($this->effectId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
            'queue_wait_unknown' => $this->jobEnqueuedAt === null,
            'enqueue_stage' => $this->resolvedEnqueueStage(),
            'enqueue_reason' => $this->enqueueReason === null || $this->enqueueReason === ''
                ? 'unknown'
                : $this->enqueueReason,
        ]);

        try {
            $order = Order::findOrFail($this->orderId);
            $effect = $this->effectId === null
                ? ($this->force
                    ? $outboundEffects->recordReceiptResend($order)
                    : $outboundEffects->recordReceipt($order))
                : TelegramOutboundEffect::query()
                    ->whereKey($this->effectId)
                    ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
                    ->firstOrFail();

            if ($effect !== null) {
                $pipeline->deliver($effect);
            }
        } catch (Throwable $exception) {
            Log::warning('telegram.receipt_materialization_failed', [
                'order_id' => $this->orderId,
                'stage' => 'receipt_job_materialization',
                'status' => 'failed',
                'error_code' => 'receipt_materialization_failed',
                'exception' => $exception::class,
            ]);

            throw $exception;
        } finally {
            DogfoodTiming::log('dogfood.telegram.receipt_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }

    private function resolvedEnqueueStage(): string
    {
        return in_array($this->enqueueStage, self::ENQUEUE_STAGES, true)
            ? $this->enqueueStage
            : 'unknown';
    }
}
