<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Exceptions\TelegramTransportException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelegramOutboundEffectService
{
    public const TYPE_MANUAL_CHECKOUT = TelegramOutboundEffectType::ManualCheckoutInstructions->value;

    public const TEMPLATE_MANUAL_CHECKOUT = 'manual-payment-v1';

    public function recordManualCheckout(Order $order, Payment $payment, ?Customer $customer = null): ?TelegramOutboundEffect
    {
        $customer ??= $order->customer;

        if ($customer?->telegram_id === null
            || $payment->provider !== PaymentProvider::MANUAL
            || $payment->method !== 'QR_MANUAL') {
            return null;
        }

        $effectKey = self::manualCheckoutEffectKey((string) $order->getKey());

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => self::TYPE_MANUAL_CHECKOUT,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE,
                'telegram_method' => 'sendPhoto',
                'locale' => $customer->locale,
                'template_version' => self::TEMPLATE_MANUAL_CHECKOUT,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    public static function manualCheckoutEffectKey(string $orderId): string
    {
        return 'order:'.$orderId.':'.self::TYPE_MANUAL_CHECKOUT.':v1';
    }

    public function forOrder(string $orderId): ?TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->where('effect_type', self::TYPE_MANUAL_CHECKOUT)
            ->latest('created_at')
            ->first();
    }

    /** @return Builder<TelegramOutboundEffect> */
    public function readyToDispatchQuery(?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return TelegramOutboundEffect::query()
            ->whereIn('state', [TelegramOutboundEffectState::Pending->value, TelegramOutboundEffectState::Failed->value])
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $at);
            });
    }

    public function claim(string $effectId): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effectId);
            $now = now();

            if (! $this->isClaimable($effect, $now)) {
                return null;
            }

            $token = Str::random(64);
            $leaseSeconds = max(60, (int) config('services.telegram.claim_lease_seconds', 600));

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sending->value,
                'attempts' => $effect->attempts + 1,
                'next_attempt_at' => null,
                'lease_until' => $now->copy()->addSeconds($leaseSeconds),
                'claim_token' => $token,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => null,
            ])->save();

            $this->markLegacyClaim($effect, $now);

            return $effect->fresh();
        });
    }

    public function processManualCheckout(TelegramOutboundEffect $effect, TelegramCheckoutIntents $checkoutIntents): void
    {
        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()->with(['customer', 'orderItems'])->findOrFail($claimed->source_id);
            $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => 'manual_payment_instructions',
                'payment_mode' => 'manual',
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => $checkoutIntents->sendPaymentInstructions(
                (string) $claimed->telegram_chat_id,
                $order,
            ));
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new RuntimeException('Telegram checkout instruction did not return a message ID.');
            }

            $this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId);
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

            throw $exception;
        }
    }

    public function finalizeSent(string $effectId, string $claimToken, string $messageId): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $messageId): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => $now,
                'unknown_at' => null,
                'failed_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => ['telegram_response' => 'ok'],
            ])->save();

            $this->markLegacySent($effect, $messageId, $now);

            return true;
        });
    }

    public function finalizeFailed(string $effectId, string $claimToken, Throwable $exception): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $exception): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => $now,
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => $now->copy()->addSeconds($this->retryDelaySeconds($effect->attempts)),
                'last_error_code' => $this->failureCode($exception),
                'last_error' => 'Telegram delivery failed before completion.',
                'outcome_metadata' => $this->safeExceptionMetadata($exception),
            ])->save();

            $this->clearLegacyClaim($effect);

            return true;
        });
    }

    public function finalizeUnknown(string $effectId, string $claimToken, Throwable $exception): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $exception): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Unknown->value,
                'lease_until' => null,
                'claim_token' => null,
                'next_attempt_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'unknown_at' => $now,
                'manual_review_at' => null,
                'last_error_code' => 'telegram_transport_ambiguous',
                'last_error' => 'Telegram delivery outcome is unknown and requires review.',
                'outcome_metadata' => $this->safeExceptionMetadata($exception),
            ])->save();

            // Keep the legacy claim as a compatibility projection. The
            // effect ledger is authoritative and prevents the old recovery
            // branch from blindly sending this effect again.

            return true;
        });
    }

    public function recoverExpiredLeases(?CarbonInterface $before = null): int
    {
        $before ??= now();

        return TelegramOutboundEffect::query()
            ->where('state', TelegramOutboundEffectState::Sending->value)
            ->whereNotNull('lease_until')
            ->where('lease_until', '<=', $before)
            ->update([
                'state' => TelegramOutboundEffectState::Unknown->value,
                'lease_until' => null,
                'claim_token' => null,
                'next_attempt_at' => null,
                'unknown_at' => now(),
                'manual_review_at' => null,
                'last_error_code' => 'lease_expired',
                'last_error' => 'Worker lease expired before Telegram delivery was finalized.',
                'outcome_metadata' => json_encode(['reason' => 'worker_lease_expired'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function promoteToManualReview(string $effectId): bool
    {
        return TelegramOutboundEffect::query()
            ->whereKey($effectId)
            ->where('state', TelegramOutboundEffectState::Unknown->value)
            ->update([
                'state' => TelegramOutboundEffectState::ManualReview->value,
                'manual_review_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    public function hasActiveLegacyClaim(TelegramOutboundEffect $effect): bool
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_CHECKOUT) {
            return false;
        }

        $startedAt = Order::query()->whereKey($effect->source_id)->value('manual_checkout_customer_notification_started_at');

        return $startedAt !== null
            && Carbon::parse($startedAt)->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }

    public function isAmbiguous(Throwable $exception): bool
    {
        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if ($candidate instanceof TelegramTransportException || $candidate instanceof ConnectionException) {
                return true;
            }

            if ($candidate instanceof RequestException) {
                $status = $candidate->response?->status();

                if ($status === null || $status === 408 || $status === 429 || $status >= 500) {
                    return true;
                }
            }

            $message = strtolower($candidate->getMessage());
            if (str_contains($message, 'request failed')
                || str_contains($message, 'connection')
                || str_contains($message, 'timed out')
                || str_contains($message, 'timeout')) {
                return true;
            }
        }

        return false;
    }

    private function isClaimable(TelegramOutboundEffect $effect, CarbonInterface $at): bool
    {
        if (! in_array($effect->state, [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Failed], true)) {
            return false;
        }

        return $effect->next_attempt_at === null || $effect->next_attempt_at->lte($at);
    }

    private function claimMatches(?TelegramOutboundEffect $effect, string $claimToken): bool
    {
        return $effect !== null
            && $effect->state === TelegramOutboundEffectState::Sending
            && $effect->claim_token !== null
            && hash_equals((string) $effect->claim_token, $claimToken);
    }

    private function markLegacyClaim(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_CHECKOUT) {
            return;
        }

        Order::query()->whereKey($effect->source_id)->update([
            'manual_checkout_customer_notification_started_at' => $at,
            'updated_at' => now(),
        ]);
    }

    private function markLegacySent(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_CHECKOUT) {
            return;
        }

        $values = [
            'manual_checkout_customer_notified_at' => $at,
            'manual_checkout_customer_notification_started_at' => null,
            'manual_checkout_customer_notification_chat_id' => $effect->telegram_chat_id,
            'manual_checkout_customer_notification_message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];

        Order::query()->whereKey($effect->source_id)->update($values + ['updated_at' => now()]);
    }

    private function clearLegacyClaim(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_CHECKOUT) {
            return;
        }

        Order::query()->whereKey($effect->source_id)->update([
            'manual_checkout_customer_notification_started_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function retryDelaySeconds(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 5,
            $attempts === 2 => 30,
            default => 120,
        };
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response !== null) {
            return 'telegram_http_'.(int) $exception->response->status();
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'message id')
            ? 'telegram_response_missing_message_id'
            : (str_contains($message, 'image') || str_contains($message, 'render')
                ? 'telegram_render_failed'
                : 'telegram_delivery_failed');
    }

    /** @return array{exception: string, http_status?: int} */
    private function safeExceptionMetadata(Throwable $exception): array
    {
        $metadata = ['exception' => class_basename($exception)];

        if ($exception instanceof RequestException && $exception->response !== null) {
            $metadata['http_status'] = (int) $exception->response->status();
        }

        return $metadata;
    }
}
