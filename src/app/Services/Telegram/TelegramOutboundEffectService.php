<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Exceptions\TelegramTransportException;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramOutboundEffectService
{
    public const TYPE_MANUAL_CHECKOUT = TelegramOutboundEffectType::ManualCheckoutInstructions->value;

    public const TEMPLATE_MANUAL_CHECKOUT = 'manual-payment-v1';

    /** @var list<string> */
    private const REVIEW_STATES = [
        TelegramOutboundEffectState::Unknown->value,
        TelegramOutboundEffectState::ManualReview->value,
    ];

    /** @var list<string> */
    private const SAFE_REVIEW_METADATA_KEYS = [
        'exception',
        'http_status',
        'reason',
        'telegram_response',
        'delivery_context',
        'duplicate_risk_acknowledged',
        'resolution',
        'resolution_actor_id',
        'resolution_at',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

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
    public function reviewQuery(): Builder
    {
        return TelegramOutboundEffect::query()
            ->whereIn('state', self::REVIEW_STATES)
            ->orderByRaw('case when state = ? then 0 else 1 end', [TelegramOutboundEffectState::ManualReview->value])
            ->latest('updated_at');
    }

    /**
     * Build the only representation of an effect that an operator-facing view may receive.
     *
     * @return array<string, mixed>
     */
    public function reviewSummary(TelegramOutboundEffect $effect): array
    {
        $state = $this->stateValue($effect);
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return [
            'effect' => $effect,
            'state' => $state,
            'effect_type' => (string) $effect->effect_type,
            'source_reference' => $this->sourceReference($effect),
            'target' => $this->redactedTarget($effect),
            'queue' => (string) ($effect->queue ?: '—'),
            'telegram_method' => (string) ($effect->telegram_method ?: '—'),
            'locale' => (string) ($effect->locale ?: '—'),
            'template_version' => (string) ($effect->template_version ?: '—'),
            'sequence' => (int) $effect->sequence,
            'attempts' => (int) $effect->attempts,
            'error_code' => $this->safeErrorCode($effect->last_error_code),
            'metadata' => $this->safeReviewMetadata($metadata),
            'duplicate_risk_acknowledged' => ($metadata['duplicate_risk_acknowledged'] ?? false) === true
                || $effect->supersedes_effect_id !== null,
            'supersedes_effect_id' => $effect->supersedes_effect_id,
            'created_at' => $effect->created_at?->format('Y-m-d H:i:s') ?? '—',
            'updated_at' => $effect->updated_at?->format('Y-m-d H:i:s') ?? '—',
            'unknown_at' => $effect->unknown_at?->format('Y-m-d H:i:s') ?? '—',
            'manual_review_at' => $effect->manual_review_at?->format('Y-m-d H:i:s') ?? '—',
            'sent_at' => $effect->sent_at?->format('Y-m-d H:i:s') ?? '—',
            'can_resolve' => in_array($state, self::REVIEW_STATES, true),
        ];
    }

    public function markSent(TelegramOutboundEffect $effect, string $messageId, ?User $actor = null, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($effect, $messageId, $actor, $ip): bool {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->find($effect->getKey());
            if ($locked === null || ! $this->isReviewable($locked)) {
                return false;
            }

            $oldState = $this->stateValue($locked);
            $now = now();
            $locked->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => $now,
                'manual_review_at' => $locked->manual_review_at ?? $now,
                'failed_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($locked, [
                    'resolution' => 'operator_marked_sent',
                    'resolution_actor_id' => $actor?->getKey(),
                    'resolution_at' => $now->toIso8601String(),
                ]),
            ])->save();

            $this->markLegacySent($locked, $messageId, $now);
            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.marked_sent',
                $locked,
                ['state' => $oldState],
                [
                    'state' => TelegramOutboundEffectState::Sent->value,
                    'telegram_message_id' => $messageId,
                    'resolution' => 'operator_marked_sent',
                ],
                $actor,
                $ip,
            );

            return true;
        });
    }

    public function resend(TelegramOutboundEffect $effect, User $actor, ?string $ip = null): TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effect, $actor, $ip): TelegramOutboundEffect {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effect->getKey());
            if (! $this->isReviewable($locked)) {
                throw new InvalidArgumentException('Only unknown or manual-review effects can be resent.');
            }

            $groupKey = (string) ($locked->effect_group_key ?: $locked->effect_key);
            $latest = TelegramOutboundEffect::query()
                ->where('effect_group_key', $groupKey)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $sequence = max((int) $locked->sequence, (int) ($latest?->sequence ?? 0)) + 1;
            $effectKey = $groupKey.':resend:v'.$sequence;

            while (TelegramOutboundEffect::query()->where('effect_key', $effectKey)->exists()) {
                $sequence++;
                $effectKey = $groupKey.':resend:v'.$sequence;
            }

            $resend = TelegramOutboundEffect::query()->create([
                'effect_key' => $effectKey,
                'effect_group_key' => $groupKey,
                'supersedes_effect_id' => $locked->getKey(),
                'sequence' => $sequence,
                'effect_type' => $locked->effect_type,
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'target_type' => $locked->target_type,
                'telegram_chat_id' => $locked->telegram_chat_id,
                'queue' => $locked->queue,
                'telegram_method' => $locked->telegram_method,
                'locale' => $locked->locale,
                'template_version' => $locked->template_version,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'explicit_resend',
                    'duplicate_risk_acknowledged' => true,
                    'supersedes_effect_id' => (string) $locked->getKey(),
                ],
            ]);

            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.resent',
                $locked,
                ['state' => $this->stateValue($locked)],
                [
                    'new_effect_id' => (string) $resend->getKey(),
                    'sequence' => $sequence,
                    'duplicate_risk_acknowledged' => true,
                ],
                $actor,
                $ip,
            );

            return $resend;
        });
    }

    public function dispatchExplicitResend(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_CHECKOUT) {
            return;
        }

        SendManualCheckoutNotifications::dispatch(
            (string) $effect->source_id,
            now()->toISOString(),
            (string) $effect->getKey(),
        )->onQueue((string) ($effect->queue ?: TelegramOutboundEffect::CHECKOUT_QUEUE));
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
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['telegram_response' => 'ok']),
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
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
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
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
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

        return DB::transaction(function () use ($before): int {
            $effects = TelegramOutboundEffect::query()
                ->where('state', TelegramOutboundEffectState::Sending->value)
                ->whereNotNull('lease_until')
                ->where('lease_until', '<=', $before)
                ->lockForUpdate()
                ->get();
            $now = now();

            foreach ($effects as $effect) {
                $effect->forceFill([
                    'state' => TelegramOutboundEffectState::Unknown->value,
                    'lease_until' => null,
                    'claim_token' => null,
                    'next_attempt_at' => null,
                    'unknown_at' => $now,
                    'manual_review_at' => null,
                    'last_error_code' => 'lease_expired',
                    'last_error' => 'Worker lease expired before Telegram delivery was finalized.',
                    'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['reason' => 'worker_lease_expired']),
                ])->save();
            }

            return $effects->count();
        });
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

    private function isReviewable(TelegramOutboundEffect $effect): bool
    {
        return in_array($this->stateValue($effect), self::REVIEW_STATES, true);
    }

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }

    private function sourceReference(TelegramOutboundEffect $effect): string
    {
        if ($effect->source_type === Order::class) {
            $invoice = Order::query()->whereKey($effect->source_id)->value('invoice_number');
            if (is_string($invoice) && $invoice !== '') {
                return 'Order '.$invoice;
            }
        }

        $sourceType = class_basename((string) $effect->source_type);
        $sourceId = substr((string) $effect->source_id, 0, 8);

        return $sourceType.' #'.$sourceId;
    }

    private function redactedTarget(TelegramOutboundEffect $effect): string
    {
        $chatId = trim((string) $effect->telegram_chat_id);
        if ($chatId === '') {
            return (string) ($effect->target_type ?: 'telegram').' (not set)';
        }

        $digits = ltrim($chatId, '+-');
        $suffix = substr($digits, -4);

        return (string) ($effect->target_type ?: 'telegram').' ••••'.$suffix;
    }

    private function safeErrorCode(?string $errorCode): ?string
    {
        $errorCode = strtolower(trim((string) $errorCode));
        if ($errorCode === '') {
            return null;
        }

        return preg_match('/\\A[a-z0-9][a-z0-9_.:-]{0,79}\\z/', $errorCode) === 1
            ? $errorCode
            : 'redacted_error_code';
    }

    /** @param array<array-key, mixed> $metadata */
    private function safeReviewMetadata(array $metadata): array
    {
        $safe = [];

        foreach (self::SAFE_REVIEW_METADATA_KEYS as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($key === 'duplicate_risk_acknowledged' && is_bool($value)) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['http_status', 'resolution_actor_id'], true)
                && ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)))) {
                $safe[$key] = (int) $value;
            } elseif ($key === 'exception' && is_string($value)
                && preg_match('/\\A[A-Za-z_][A-Za-z0-9_\\\\]*\\z/', $value) === 1) {
                $safe[$key] = class_basename($value);
            } elseif (in_array($key, ['reason', 'delivery_context', 'resolution'], true)) {
                $safeValue = $this->safeErrorCode(is_string($value) ? $value : null);
                if ($safeValue !== null) {
                    $safe[$key] = $safeValue;
                }
            } elseif ($key === 'telegram_response' && $value === 'ok') {
                $safe[$key] = 'ok';
            } elseif ($key === 'resolution_at' && is_string($value)
                && preg_match('/\\A[0-9TZ: .+\\-]{1,40}\\z/', $value) === 1) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function mergeOutcomeMetadata(TelegramOutboundEffect $effect, array $updates): array
    {
        $existing = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return array_merge($existing, $updates);
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
