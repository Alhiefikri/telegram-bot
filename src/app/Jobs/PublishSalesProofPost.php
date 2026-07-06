<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\InventoryLedger;
use App\Services\Telegram\SalesProofPostFormatter;
use App\Services\Telegram\SalesProofPostNumbering;
use App\Services\Telegram\TelegramBotClient;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PublishSalesProofPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $orderId, public readonly bool $manualRetry = false, public ?string $jobEnqueuedAt = null) {}

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramBotClient $bot, SalesProofPostFormatter $formatter, SalesProofPostNumbering $numbering, InventoryLedger $inventory): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();
        DogfoodTiming::log('dogfood.telegram.sales_proof_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        $publishLock = Cache::lock('sales-proof-posts:publish', 60);
        $lockAcquired = false;
        try {
            $lockAcquired = $publishLock->get();
            if (! $lockAcquired) {
                $this->release(5);

                return;
            }

            $this->publish($bot, $formatter, $numbering, $inventory);
        } finally {
            if ($lockAcquired) {
                $publishLock->release();
            }
            DogfoodTiming::log('dogfood.telegram.sales_proof_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }

    private function publish(TelegramBotClient $bot, SalesProofPostFormatter $formatter, SalesProofPostNumbering $numbering, InventoryLedger $inventory): void
    {
        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled) {
            $this->markUnpublishable('Sales Proof Post is disabled.');

            return;
        }

        if (! $chatId) {
            $this->markUnpublishable('Store Channel chat is not configured.');

            return;
        }

        $post = DB::transaction(function () use ($inventory): ?SalesProofPost {
            $post = SalesProofPost::query()->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $post || $post->status === SalesProofPost::STATUS_SENT || $post->status === SalesProofPost::STATUS_SENDING || (! $this->manualRetry && $post->attempts >= 3)) {
                // STATUS_SENDING is the durable Telegram-send claim. A worker crash
                // after Telegram accepts the channel post leaves this state behind;
                // retries skip it instead of risking a duplicate Sales Proof Post.
                return null;
            }

            $order = Order::with(['payments'])->whereKey($this->orderId)->lockForUpdate()->first();
            $invariantFailure = $order
                ? $this->paidOrderProofInvariantFailure($order, $inventory)
                : 'Sales Proof Post requires an existing paid order.';
            if ($invariantFailure) {
                $post->update([
                    'status' => SalesProofPost::STATUS_FAILED,
                    'attempts' => $post->attempts + 1,
                    'last_error' => mb_substr($invariantFailure, 0, 1000),
                ]);

                return null;
            }

            $post->update(['status' => SalesProofPost::STATUS_SENDING, 'attempts' => $post->attempts + 1, 'last_error' => null]);

            return $post->fresh();
        });

        if (! $post) {
            return;
        }

        try {
            $order = Order::with(['customer', 'orderItems'])->findOrFail($this->orderId);
            $successfulPostNumber = $numbering->reserveNextNumber();
            $response = $bot->sendMessage($chatId, $formatter->format($order, $successfulPostNumber), [
                'inline_keyboard' => [[['text' => '🛒 Belanja Sekarang', 'url' => 'https://t.me/lhiestore_bot?start=from_channel']]],
            ]);
            $messageId = data_get($response, 'result.message_id');
            if (data_get($response, 'ok') !== true || ! $messageId) {
                throw new RuntimeException('Telegram sendMessage did not return a successful message id.');
            }

            $post->update(['status' => SalesProofPost::STATUS_SENT, 'telegram_message_id' => $messageId ? (string) $messageId : null, 'sent_at' => now(), 'last_error' => null]);
        } catch (Throwable $e) {
            $post->update(['status' => SalesProofPost::STATUS_FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($post->attempts < 3) {
                $this->release($this->backoff()[$post->attempts - 1] ?? 120);
            }
        }
    }

    private function paidOrderProofInvariantFailure(Order $order, InventoryLedger $inventory): ?string
    {
        $isPaid = $order->payment_status === PaymentStatus::Paid
            && $order->payments->contains(fn ($payment): bool => $payment->status === PaymentStatus::Paid);
        $isProcessable = in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
        if (! $isPaid || ! $isProcessable) {
            return 'Sales Proof Post requires a paid processable order.';
        }

        if (! $inventory->hasSaleProofForOrder($order)) {
            return 'Sales Proof Post requires matching inventory sale proof.';
        }

        return null;
    }

    private function markUnpublishable(string $message): void
    {
        DB::transaction(function () use ($message): void {
            $post = SalesProofPost::query()->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $post || $post->status === SalesProofPost::STATUS_SENT || (! $this->manualRetry && $post->attempts >= 3)) {
                return;
            }

            $post->update([
                'status' => SalesProofPost::STATUS_FAILED,
                'attempts' => $post->attempts + 1,
                'last_error' => mb_substr($message, 0, 1000),
            ]);
        });
    }
}
