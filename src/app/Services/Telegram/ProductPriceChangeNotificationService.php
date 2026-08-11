<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Support\Money;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductPriceChangeNotificationService
{
    private const CLAIM_TTL_MINUTES = 15;

    private const RETRY_DELAY_SECONDS = 30;

    public const BATCH_SIZE = 25;

    public const MAX_RECIPIENT_ATTEMPTS = 5;

    public const MAX_STORE_CHANNEL_ATTEMPTS = 1;

    public function __construct(private readonly TelegramBotClient $bot) {}

    public function createEventForCommittedUpdate(Product $product, array $oldValues, array $newValues): ?ProductPriceChangeEvent
    {
        $oldIdr = (int) $oldValues['price_amount'];
        $newIdr = (int) $newValues['price_amount'];
        $oldUsdt = $oldValues['usdt_price_amount'] === null ? null : (int) $oldValues['usdt_price_amount'];
        $newUsdt = $newValues['usdt_price_amount'] === null ? null : (int) $newValues['usdt_price_amount'];

        if ($oldIdr === $newIdr && $oldUsdt === $newUsdt) {
            return null;
        }

        if (! $product->is_active || $product->availableStock() < 1) {
            return null;
        }

        return ProductPriceChangeEvent::create([
            'event_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => $oldIdr,
            'new_price_amount' => $newIdr,
            'old_usdt_price_amount' => $oldUsdt,
            'new_usdt_price_amount' => $newUsdt,
        ]);
    }

    public function processBatch(int $eventId, int $batchSize = self::BATCH_SIZE): ProductPriceChangeBatchResult
    {
        if (blank(config('services.telegram.token'))) {
            return new ProductPriceChangeBatchResult(0, 0, 0, false);
        }

        $event = ProductPriceChangeEvent::query()->findOrFail($eventId);
        $storeChannelOutcome = $this->sendStoreChannel($event);
        if ($event->recipients_materialized_at === null) {
            $this->ensureRecipients($event);
            $event->forceFill(['recipients_materialized_at' => now()])->save();
        }

        $recipients = ProductPriceChangeNotificationRecipient::query()
            ->where('event_id', $event->id)
            ->whereNull('sent_at')
            ->where('attempts', '<', self::MAX_RECIPIENT_ATTEMPTS)
            ->where(function ($query): void {
                $query->whereNull('send_started_at')
                    ->orWhere('send_started_at', '<=', now()->subMinutes(self::CLAIM_TTL_MINUTES));
            })
            ->where(function ($query): void {
                $query->whereNull('failed_at')
                    ->orWhere('failed_at', '<=', now()->subSeconds(self::RETRY_DELAY_SECONDS));
            })
            ->orderBy('id')
            ->limit(max(1, $batchSize))
            ->get();

        $transientFailures = $storeChannelOutcome === 'transient_failure' ? 1 : 0;
        $terminalFailures = $storeChannelOutcome === 'terminal_failure' ? 1 : 0;

        foreach ($recipients as $recipient) {
            $outcome = $this->sendRecipient($event, $recipient);
            $transientFailures += $outcome === 'transient_failure' ? 1 : 0;
            $terminalFailures += $outcome === 'terminal_failure' ? 1 : 0;
        }

        return new ProductPriceChangeBatchResult(
            processed: $recipients->count(),
            transientFailures: $transientFailures,
            terminalFailures: $terminalFailures,
            hasMore: $this->hasPendingRecipients($event->id) || $this->hasPendingStoreChannel($event->id),
        );
    }

    private function sendStoreChannel(ProductPriceChangeEvent $event): string
    {
        $chatId = $this->storeChannelTarget();
        if ($chatId === null) {
            return 'skipped';
        }

        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($event, $token): ?ProductPriceChangeEvent {
            $locked = ProductPriceChangeEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->store_channel_sent_at !== null) {
                return null;
            }

            if ($locked->store_channel_attempts >= self::MAX_STORE_CHANNEL_ATTEMPTS) {
                return null;
            }

            if ($locked->store_channel_send_started_at !== null && $locked->store_channel_send_started_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) {
                return null;
            }

            if ($locked->store_channel_failed_at !== null && $locked->store_channel_failed_at->gt(now()->subSeconds(self::RETRY_DELAY_SECONDS))) {
                return null;
            }

            $locked->forceFill([
                'store_channel_send_started_at' => now(),
                'store_channel_send_claim_token' => $token,
                'store_channel_failed_at' => null,
                'store_channel_last_error' => null,
                'store_channel_attempts' => $locked->store_channel_attempts + 1,
            ])->save();

            return $locked->fresh();
        }, 5);

        if (! $claimed) {
            return 'skipped';
        }

        try {
            $response = $this->bot->sendMessage($chatId, $this->message($claimed, true));
            $messageId = data_get($response, 'result.message_id');
            if (data_get($response, 'ok') !== true || ! $messageId) {
                throw new RuntimeException('Telegram sendMessage did not return a successful Store Channel message ID.');
            }
        } catch (Throwable $e) {
            $terminal = $e instanceof RequestException && $e->response?->status() === 403;
            ProductPriceChangeEvent::query()
                ->whereKey($claimed->id)
                ->where('store_channel_send_claim_token', $token)
                ->whereNull('store_channel_sent_at')
                ->update([
                    'store_channel_send_started_at' => null,
                    'store_channel_send_claim_token' => null,
                    'store_channel_failed_at' => now(),
                    'store_channel_last_error' => substr(TelegramBotApiRedactor::redactText($e::class.': '.$e->getMessage()), 0, 2000),
                    'store_channel_attempts' => $terminal ? self::MAX_STORE_CHANNEL_ATTEMPTS : $claimed->store_channel_attempts,
                ]);

            Log::warning('telegram.product_price_change_store_channel_notification_failed', [
                'event_id' => $event->id,
                'attempts' => $claimed->store_channel_attempts,
                'terminal' => $terminal,
                'status' => $e instanceof RequestException ? $e->response?->status() : null,
                'exception' => $e::class,
                'message' => TelegramBotApiRedactor::redactText($e->getMessage()),
            ]);

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }

        ProductPriceChangeEvent::query()
            ->whereKey($claimed->id)
            ->where('store_channel_send_claim_token', $token)
            ->whereNull('store_channel_sent_at')
            ->update([
                'store_channel_sent_at' => now(),
                'store_channel_send_started_at' => null,
                'store_channel_send_claim_token' => null,
                'store_channel_failed_at' => null,
                'store_channel_last_error' => null,
                'store_channel_message_id' => (string) $messageId,
            ]);

        return 'sent';
    }

    private function ensureRecipients(ProductPriceChangeEvent $event): void
    {
        Customer::query()
            ->whereNotNull('telegram_id')
            ->select(['id', 'telegram_id', 'locale'])
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($event): void {
                $now = now();
                $rows = $customers
                    ->filter(fn (Customer $customer): bool => trim((string) $customer->telegram_id) !== '')
                    ->map(fn (Customer $customer): array => [
                        'event_id' => $event->id,
                        'customer_id' => $customer->id,
                        'telegram_id' => (string) $customer->telegram_id,
                        'locale' => $customer->locale === 'en' ? 'en' : 'id',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    ProductPriceChangeNotificationRecipient::query()->insertOrIgnore($rows);
                }
            });
    }

    private function sendRecipient(ProductPriceChangeEvent $event, ProductPriceChangeNotificationRecipient $recipient): string
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($recipient, $token): ?ProductPriceChangeNotificationRecipient {
            $locked = ProductPriceChangeNotificationRecipient::query()
                ->whereKey($recipient->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->sent_at !== null) {
                return null;
            }

            if ($locked->attempts >= self::MAX_RECIPIENT_ATTEMPTS) {
                return null;
            }

            if ($locked->send_started_at !== null && $locked->send_started_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) {
                return null;
            }

            $locked->forceFill([
                'send_started_at' => now(),
                'send_claim_token' => $token,
                'failed_at' => null,
                'last_error' => null,
                'attempts' => $locked->attempts + 1,
            ])->save();

            return $locked->fresh();
        }, 5);

        if (! $claimed) {
            return 'skipped';
        }

        try {
            // Telegram has no transactional exactly-once send. The durable recipient row prevents normal
            // retries from resending once sent_at is stored, but a process crash after this sendMessage call
            // and before the sent_at update below can still produce one duplicate message on recovery.
            $this->bot->sendMessage(
                $claimed->telegram_id,
                $this->message($event, $claimed->locale === 'en'),
                $this->replyMarkup($event, $claimed->locale === 'en'),
            );
        } catch (Throwable $e) {
            $terminal = $e instanceof RequestException && $e->response?->status() === 403;
            ProductPriceChangeNotificationRecipient::query()
                ->whereKey($claimed->id)
                ->where('send_claim_token', $token)
                ->whereNull('sent_at')
                ->update([
                    'send_started_at' => null,
                    'send_claim_token' => null,
                    'failed_at' => now(),
                    'last_error' => substr(TelegramBotApiRedactor::redactText($e::class.': '.$e->getMessage()), 0, 2000),
                    'attempts' => $terminal ? self::MAX_RECIPIENT_ATTEMPTS : $claimed->attempts,
                ]);

            Log::warning('telegram.product_price_change_notification_failed', [
                'event_id' => $event->id,
                'recipient_id' => $claimed->id,
                'customer_id' => $claimed->customer_id,
                'attempts' => $claimed->attempts,
                'terminal' => $terminal,
                'status' => $e instanceof RequestException ? $e->response?->status() : null,
                'exception' => $e::class,
                'message' => TelegramBotApiRedactor::redactText($e->getMessage()),
            ]);

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }

        ProductPriceChangeNotificationRecipient::query()
            ->whereKey($claimed->id)
            ->where('send_claim_token', $token)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => now(),
                'send_started_at' => null,
                'send_claim_token' => null,
                'failed_at' => null,
                'last_error' => null,
            ]);

        return 'sent';
    }

    private function hasPendingRecipients(int $eventId): bool
    {
        return ProductPriceChangeNotificationRecipient::query()
            ->where('event_id', $eventId)
            ->whereNull('sent_at')
            ->where('attempts', '<', self::MAX_RECIPIENT_ATTEMPTS)
            ->exists();
    }

    private function hasPendingStoreChannel(int $eventId): bool
    {
        if ($this->storeChannelTarget() === null) {
            return false;
        }

        return ProductPriceChangeEvent::query()
            ->whereKey($eventId)
            ->whereNull('store_channel_sent_at')
            ->where('store_channel_attempts', '<', self::MAX_STORE_CHANNEL_ATTEMPTS)
            ->exists();
    }

    private function storeChannelTarget(): ?string
    {
        $setting = StoreChannelSetting::current();
        $target = trim((string) ($setting->telegram_chat_id ?: $setting->channel_username));

        return $target === '' ? null : $target;
    }

    private function message(ProductPriceChangeEvent $event, bool $english): string
    {
        $decrease = $this->isPureDecrease($event);
        $title = $english
            ? ($decrease ? '🏷️ Price Drop' : '📢 Price Update')
            : ($decrease ? '🏷️ Harga Turun' : '📢 Update Harga');

        $lines = [
            $title,
            '━━━━━━━━━━━━━━',
            $english
                ? ($decrease ? 'Good news for you.' : 'A product price has been updated.')
                : ($decrease ? 'Kabar bagus buat kamu.' : 'Ada update harga produk.'),
            '📦 '.$event->product_name,
            '',
            '💵 QRIS',
            $this->formatIdr($event->old_price_amount, $english).' → '.$this->formatIdr($event->new_price_amount, $english),
        ];

        $usdtLine = $this->usdtLine($event, $english);
        if ($usdtLine !== null) {
            $lines[] = '';
            $lines[] = '💎 Binance Pay';
            $lines[] = $usdtLine;
        }

        $lines[] = '';
        $savingsLine = $this->savingsLine($event, $english);
        if ($decrease && $savingsLine !== null) {
            $lines[] = $savingsLine;
            $lines[] = $english
                ? 'The new price is ready for your next checkout.'
                : 'Harga baru sudah siap untuk checkout berikutnya.';
        } else {
            $lines[] = $english
                ? 'The new price applies to future checkouts.'
                : 'Harga baru berlaku untuk checkout berikutnya.';
            $lines[] = $english
                ? 'Existing orders keep their previous price.'
                : 'Order yang sudah dibuat tetap memakai harga sebelumnya.';
        }

        $lines[] = '';
        $lines[] = $english ? 'Need help? Reply with /support.' : 'Butuh bantuan? Balas /support ya.';

        return implode("\n", $lines);
    }

    private function isPureDecrease(ProductPriceChangeEvent $event): bool
    {
        $directions = [];
        if ($event->old_price_amount !== $event->new_price_amount) {
            $directions[] = $event->new_price_amount < $event->old_price_amount ? 'down' : 'up';
        }
        if ($event->old_usdt_price_amount !== null && $event->new_usdt_price_amount !== null && $event->old_usdt_price_amount !== $event->new_usdt_price_amount) {
            $directions[] = $event->new_usdt_price_amount < $event->old_usdt_price_amount ? 'down' : 'up';
        }
        if ($event->old_usdt_price_amount === null xor $event->new_usdt_price_amount === null) {
            $directions[] = 'changed';
        }

        return $directions !== [] && collect($directions)->every(fn (string $direction): bool => $direction === 'down');
    }

    private function usdtLine(ProductPriceChangeEvent $event, bool $english): ?string
    {
        if ($event->old_usdt_price_amount === $event->new_usdt_price_amount) {
            return null;
        }

        if ($event->old_usdt_price_amount === null && $event->new_usdt_price_amount !== null) {
            return $english
                ? 'Now available at '.Money::usdt($event->new_usdt_price_amount)
                : 'Sekarang tersedia di '.Money::usdt($event->new_usdt_price_amount);
        }

        if ($event->old_usdt_price_amount !== null && $event->new_usdt_price_amount === null) {
            return $english ? 'No longer available for Binance Pay.' : 'Tidak tersedia lagi untuk Binance Pay.';
        }

        return Money::usdt($event->old_usdt_price_amount).' → '.Money::usdt($event->new_usdt_price_amount);
    }

    private function savingsLine(ProductPriceChangeEvent $event, bool $english): ?string
    {
        $savings = [];
        if ($event->new_price_amount < $event->old_price_amount) {
            $savings[] = $this->formatIdr($event->old_price_amount - $event->new_price_amount, $english);
        }
        if ($event->old_usdt_price_amount !== null
            && $event->new_usdt_price_amount !== null
            && $event->new_usdt_price_amount < $event->old_usdt_price_amount) {
            $savings[] = Money::usdt($event->old_usdt_price_amount - $event->new_usdt_price_amount);
        }

        if ($savings === []) {
            return null;
        }

        return ($english ? 'You save ' : 'Lebih hemat ').implode(' + ', $savings).'.';
    }

    private function replyMarkup(ProductPriceChangeEvent $event, bool $english): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => $english ? '🛒 View Product' : '🛒 Lihat Produk', 'callback_data' => 'product:'.$event->product_id],
            ]],
        ];
    }

    private function formatIdr(int $amount, bool $english): string
    {
        return Money::rupiah($amount, $english ? ',' : '.');
    }
}
