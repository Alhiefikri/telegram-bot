<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Storefront\StorefrontUrl;
use App\Support\DogfoodTiming;
use App\Support\Money;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductPriceChangeNotificationService
{
    private const RETRY_DELAY_SECONDS = 30;

    public const BATCH_SIZE = 25;

    public const MAX_RECIPIENT_ATTEMPTS = 5;

    public const MAX_STORE_CHANNEL_ATTEMPTS = 1;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly StorefrontUrl $urls,
        private ?TelegramOutboundEffectService $outboundEffects = null,
        private ?TelegramOutboundResponseValidator $responseValidator = null,
    ) {}

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

        return DB::transaction(function () use ($product, $oldIdr, $newIdr, $oldUsdt, $newUsdt): ProductPriceChangeEvent {
            $event = ProductPriceChangeEvent::create([
                'event_key' => (string) Str::uuid(),
                'product_id' => $product->id,
                'product_name' => $product->name,
                'old_price_amount' => $oldIdr,
                'new_price_amount' => $newIdr,
                'old_usdt_price_amount' => $oldUsdt,
                'new_usdt_price_amount' => $newUsdt,
            ]);

            // The event, its immutable audience snapshot, and the pending
            // ledger units commit with the product price mutation.
            $this->materializeEvent($event);

            return $event;
        }, 5);
    }

    public function processBatch(int $eventId, int $batchSize = self::BATCH_SIZE): ProductPriceChangeBatchResult
    {
        if (blank(config('services.telegram.token'))) {
            return new ProductPriceChangeBatchResult(0, 0, 0, false);
        }

        $event = ProductPriceChangeEvent::query()->findOrFail($eventId);
        $this->materializeEvent($event);

        $storeChannelOutcome = $this->sendStoreChannelEffect($event);
        $effects = $this->dueRecipientEffects($event->id, $batchSize);

        $transientFailures = $storeChannelOutcome === 'transient_failure' ? 1 : 0;
        $terminalFailures = $storeChannelOutcome === 'terminal_failure' ? 1 : 0;

        foreach ($effects as $effect) {
            $outcome = $this->sendRecipientEffect($event, $effect);
            $transientFailures += $outcome === 'transient_failure' ? 1 : 0;
            $terminalFailures += $outcome === 'terminal_failure' ? 1 : 0;
        }

        return new ProductPriceChangeBatchResult(
            processed: $effects->count(),
            transientFailures: $transientFailures,
            terminalFailures: $terminalFailures,
            hasMore: $transientFailures > 0 || $this->hasPendingEffects($event->id),
        );
    }

    private function sendStoreChannelEffect(ProductPriceChangeEvent $event): string
    {
        $effect = $this->outboundEffects()->readyToDispatchQuery()
            ->where('source_type', ProductPriceChangeEvent::class)
            ->where('source_id', (string) $event->getKey())
            ->where('effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL)
            ->first();
        if ($effect === null) {
            return 'skipped';
        }

        return $this->sendEffect($event, $effect, true);
    }

    private function materializeEvent(ProductPriceChangeEvent $event): void
    {
        if ($event->recipients_materialized_at === null) {
            Customer::query()
                ->whereNotNull('telegram_id')
                ->select(['id', 'telegram_id', 'locale'])
                ->orderBy('id')
                ->chunkById(200, function ($customers) use ($event): void {
                    DB::transaction(function () use ($customers, $event): void {
                        $now = now();
                        $rows = $customers
                            ->filter(fn (Customer $customer): bool => trim((string) $customer->telegram_id) !== '')
                            ->map(fn (Customer $customer): array => [
                                'event_id' => $event->id,
                                'customer_id' => $customer->id,
                                'telegram_id' => (string) $customer->telegram_id,
                                'locale' => $this->locale->code($customer),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ])
                            ->values()
                            ->all();

                        if ($rows !== []) {
                            ProductPriceChangeNotificationRecipient::query()->insertOrIgnore($rows);
                        }
                    }, 5);
                });

            $event->forceFill(['recipients_materialized_at' => now()])->save();
        }

        ProductPriceChangeNotificationRecipient::query()
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use ($event): void {
                DB::transaction(function () use ($recipients, $event): void {
                    foreach ($recipients as $recipient) {
                        $this->materializeRecipientEffect($event, $recipient);
                    }
                }, 5);
            });

        $this->materializeStoreChannelEffect($event);
    }

    private function materializeRecipientEffect(
        ProductPriceChangeEvent $event,
        ProductPriceChangeNotificationRecipient $recipient,
    ): ?TelegramOutboundEffect {
        $effectKey = TelegramOutboundEffectService::priceChangeRecipientEffectKey(
            (string) $event->getKey(),
            (string) $recipient->getKey(),
        );
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $state = TelegramOutboundEffectState::Pending->value;
        $sentAt = null;
        $failedAt = null;
        $unknownAt = null;
        $nextAttemptAt = now();
        $lastErrorCode = null;
        $lastError = null;

        if ($recipient->sent_at !== null) {
            $state = TelegramOutboundEffectState::Sent->value;
            $sentAt = $recipient->sent_at;
            $nextAttemptAt = null;
        } elseif ($recipient->failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $recipient->failed_at;
            $nextAttemptAt = (int) $recipient->attempts >= self::MAX_RECIPIENT_ATTEMPTS
                ? null
                : $recipient->failed_at->copy()->addSeconds(self::RETRY_DELAY_SECONDS);
            $lastErrorCode = (int) $recipient->attempts >= self::MAX_RECIPIENT_ATTEMPTS
                ? 'price_change_recipient_attempts_exhausted'
                : 'legacy_price_change_failure';
            $lastError = 'Legacy price notification delivery failed before completion.';
        }

        return $this->outboundEffects()->recordPriceChangeNotificationRecipient(
            recipient: $recipient,
            eventId: $event->getKey(),
            state: $state,
            sentAt: $sentAt,
            failedAt: $failedAt,
            unknownAt: $unknownAt,
            nextAttemptAt: $nextAttemptAt,
            lastErrorCode: $lastErrorCode,
            lastError: $lastError,
        );
    }

    private function materializeStoreChannelEffect(ProductPriceChangeEvent $event): ?TelegramOutboundEffect
    {
        $chatId = $this->storeChannelTarget();
        if ($chatId === null) {
            return null;
        }

        $effectKey = TelegramOutboundEffectService::priceChangeStoreChannelEffectKey((string) $event->getKey());
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $state = TelegramOutboundEffectState::Pending->value;
        $sentAt = null;
        $failedAt = null;
        $unknownAt = null;
        $nextAttemptAt = now();
        $lastErrorCode = null;
        $lastError = null;

        if ($event->store_channel_sent_at !== null) {
            $state = TelegramOutboundEffectState::Sent->value;
            $sentAt = $event->store_channel_sent_at;
            $nextAttemptAt = null;
        } elseif ($event->store_channel_failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $event->store_channel_failed_at;
            $nextAttemptAt = null;
            $lastErrorCode = 'price_change_store_channel_attempts_exhausted';
            $lastError = 'Legacy price Store Channel delivery failed before completion.';
        }

        return $this->outboundEffects()->recordPriceChangeStoreChannel(
            event: $event,
            chatId: $chatId,
            state: $state,
            sentAt: $sentAt,
            failedAt: $failedAt,
            unknownAt: $unknownAt,
            nextAttemptAt: $nextAttemptAt,
            lastErrorCode: $lastErrorCode,
            lastError: $lastError,
        );
    }

    private function dueRecipientEffects(int $eventId, int $batchSize)
    {
        return $this->outboundEffects()->readyToDispatchQuery()
            ->where('source_type', ProductPriceChangeNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
            ->whereIn('source_id', ProductPriceChangeNotificationRecipient::query()
                ->where('event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->orderByRaw('CAST(source_id AS BIGINT)')
            ->limit(max(1, $batchSize))
            ->get();
    }

    private function sendRecipientEffect(ProductPriceChangeEvent $event, TelegramOutboundEffect $effect): string
    {
        return $this->sendEffect($event, $effect, false);
    }

    private function sendEffect(ProductPriceChangeEvent $event, TelegramOutboundEffect $effect, bool $storeChannel): string
    {
        $claimed = $this->outboundEffects()->claim((string) $effect->getKey());
        if ($claimed === null) {
            return 'skipped';
        }

        try {
            if (! $storeChannel) {
                $recipient = ProductPriceChangeNotificationRecipient::query()->find($claimed->source_id);
                if ($recipient === null) {
                    throw new RuntimeException('price_change_recipient_missing');
                }
                $locale = $this->locale->codeForLocale((string) $claimed->locale);
                $response = $this->bot->sendMessage(
                    $claimed->telegram_chat_id,
                    $this->message($event, $locale),
                    $this->replyMarkup($event, $locale),
                );
            } else {
                $response = $this->bot->sendMessage(
                    $claimed->telegram_chat_id,
                    $this->message($event, TelegramLocale::STORE_CHANNEL_LOCALE),
                    $this->storeChannelReplyMarkup($event),
                );
            }

            $messageId = $this->responseValidator()->messageIdFromResponse($response, 'Telegram price notification');

            return $this->outboundEffects()->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            ) ? 'sent' : 'skipped';
        } catch (Throwable $exception) {
            if ($this->isPriceNotificationAmbiguous($exception)) {
                $finalized = $this->outboundEffects()->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                $this->logEffectFailure($event, $claimed, $exception, 'unknown');

                return $finalized ? 'unknown' : 'skipped';
            }

            $finalized = $this->outboundEffects()->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                self::RETRY_DELAY_SECONDS,
            );
            if (! $finalized) {
                return 'skipped';
            }

            $fresh = TelegramOutboundEffect::query()->find($claimed->getKey());
            $terminal = $fresh !== null && $this->outboundEffects()->isTerminalFailure($fresh);
            $this->logEffectFailure($event, $claimed, $exception, $terminal ? 'terminal_failure' : 'transient_failure');

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }
    }

    private function hasPendingEffects(int $eventId): bool
    {
        $recipientPending = $this->outboundEffects()->readyToDispatchQuery()
            ->where('source_type', ProductPriceChangeNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
            ->whereIn('source_id', ProductPriceChangeNotificationRecipient::query()
                ->where('event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->exists();
        if ($recipientPending) {
            return true;
        }

        return $this->outboundEffects()->readyToDispatchQuery()
            ->where('source_type', ProductPriceChangeEvent::class)
            ->where('source_id', (string) $eventId)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL)
            ->exists();
    }

    private function isPriceNotificationAmbiguous(Throwable $exception): bool
    {
        if ($exception instanceof RequestException
            && in_array($exception->response?->status(), [400, 403, 429], true)) {
            return false;
        }

        return $this->outboundEffects()->isAmbiguous($exception);
    }

    private function logEffectFailure(
        ProductPriceChangeEvent $event,
        TelegramOutboundEffect $effect,
        Throwable $exception,
        string $status,
    ): void {
        Log::warning('telegram.product_price_change_effect_failed', [
            'event_id' => $event->id,
            'effect_id' => $effect->id,
            'effect_type' => $effect->effect_type,
            'recipient_correlation_id' => $effect->telegram_chat_id === ''
                ? null
                : DogfoodTiming::correlation($effect->telegram_chat_id),
            'attempts' => $effect->attempts,
            'status' => $status,
            'http_status' => $exception instanceof RequestException ? $exception->response?->status() : null,
            'exception' => $exception::class,
        ]);
    }

    private function outboundEffects(): TelegramOutboundEffectService
    {
        return $this->outboundEffects ??= app(TelegramOutboundEffectService::class);
    }

    private function responseValidator(): TelegramOutboundResponseValidator
    {
        return $this->responseValidator ??= app(TelegramOutboundResponseValidator::class);
    }

    private function storeChannelTarget(): ?string
    {
        $setting = StoreChannelSetting::current();
        $target = trim((string) ($setting->telegram_chat_id ?: $setting->channel_username));

        return $target === '' ? null : $target;
    }

    private function message(ProductPriceChangeEvent $event, string $locale): string
    {
        $decrease = $this->isPureDecrease($event);
        $title = $this->locale->translateLocale($locale, $decrease
            ? 'notification.price.title.drop'
            : 'notification.price.title.update');

        $lines = [
            $title,
            '━━━━━━━━━━━━━━',
            $this->locale->translateLocale($locale, $decrease
                ? 'notification.price.lead.drop'
                : 'notification.price.lead.update'),
            '📦 '.$event->product_name,
            '',
            $this->locale->translateLocale($locale, 'notification.price.qris.label'),
            $this->formatIdr($event->old_price_amount, $locale).' → '.$this->formatIdr($event->new_price_amount, $locale),
        ];

        $usdtLine = $this->usdtLine($event, $locale);
        if ($usdtLine !== null) {
            $lines[] = '';
            $lines[] = $this->locale->translateLocale($locale, 'notification.price.binance.label');
            $lines[] = $usdtLine;
        }

        $lines[] = '';
        $savingsLine = $this->savingsLine($event, $locale);
        if ($decrease && $savingsLine !== null) {
            $lines[] = $savingsLine;
            $lines[] = $this->locale->translateLocale($locale, 'notification.price.new_price_ready');
        } else {
            $lines[] = $this->locale->translateLocale($locale, 'notification.price.future_checkout');
            $lines[] = $this->locale->translateLocale($locale, 'notification.price.existing_orders');
        }

        $lines[] = '';
        $lines[] = $this->locale->translateLocale($locale, 'notification.price.help');

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

    private function usdtLine(ProductPriceChangeEvent $event, string $locale): ?string
    {
        if ($event->old_usdt_price_amount === $event->new_usdt_price_amount) {
            return null;
        }

        if ($event->old_usdt_price_amount === null && $event->new_usdt_price_amount !== null) {
            return $this->locale->translateLocale($locale, 'notification.price.usdt.now_available', [
                'amount' => Money::usdt($event->new_usdt_price_amount),
            ]);
        }

        if ($event->old_usdt_price_amount !== null && $event->new_usdt_price_amount === null) {
            return $this->locale->translateLocale($locale, 'notification.price.usdt.unavailable');
        }

        return Money::usdt($event->old_usdt_price_amount).' → '.Money::usdt($event->new_usdt_price_amount);
    }

    private function savingsLine(ProductPriceChangeEvent $event, string $locale): ?string
    {
        $savings = [];
        if ($event->new_price_amount < $event->old_price_amount) {
            $savings[] = $this->formatIdr($event->old_price_amount - $event->new_price_amount, $locale);
        }
        if ($event->old_usdt_price_amount !== null
            && $event->new_usdt_price_amount !== null
            && $event->new_usdt_price_amount < $event->old_usdt_price_amount) {
            $savings[] = Money::usdt($event->old_usdt_price_amount - $event->new_usdt_price_amount);
        }

        if ($savings === []) {
            return null;
        }

        return $this->locale->translateLocale($locale, 'notification.price.savings', [
            'amount' => implode(' + ', $savings),
        ]);
    }

    private function replyMarkup(ProductPriceChangeEvent $event, string $locale): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => $this->locale->translateLocale($locale, 'notification.price.cta'), 'callback_data' => 'product:'.$event->product_id],
            ]],
        ];
    }

    private function storeChannelReplyMarkup(ProductPriceChangeEvent $event): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $this->locale->translateLocale(TelegramLocale::STORE_CHANNEL_LOCALE, 'notification.store_channel.cta'),
                    'url' => $this->urls->telegramProductStartUrlForId((int) $event->product_id),
                ],
            ]],
        ];
    }

    private function formatIdr(int $amount, string $locale): string
    {
        return Money::rupiah($amount, $locale === 'en' ? ',' : '.');
    }
}
