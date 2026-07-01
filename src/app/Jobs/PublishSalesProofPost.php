<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\Telegram\SalesProofPostFormatter;
use App\Services\Telegram\TelegramBotClient;
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

    public function __construct(public readonly string $orderId) {}

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramBotClient $bot, SalesProofPostFormatter $formatter): void
    {
        $publishLock = Cache::lock('sales-proof-posts:publish', 60);
        if (! $publishLock->get()) {
            $this->release(5);

            return;
        }

        try {
            $this->publish($bot, $formatter);
        } finally {
            $publishLock->release();
        }
    }

    private function publish(TelegramBotClient $bot, SalesProofPostFormatter $formatter): void
    {
        $setting = StoreChannelSetting::current();
        $chatId = $setting->telegram_chat_id ?: $setting->channel_username;
        if (! $setting->sales_proof_enabled || ! $chatId) {
            return;
        }

        $post = DB::transaction(function (): ?SalesProofPost {
            $post = SalesProofPost::query()->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $post || $post->status === SalesProofPost::STATUS_SENT || $post->attempts >= 3) {
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
            $successfulPostNumber = SalesProofPost::query()->where('status', SalesProofPost::STATUS_SENT)->count() + 1;
            $response = $bot->sendMessage($chatId, $formatter->format($order, $successfulPostNumber), [
                'inline_keyboard' => [[['text' => '🛒 Belanja sekarang', 'url' => 'https://t.me/lhiestore_bot?start=from_channel']]],
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
}
