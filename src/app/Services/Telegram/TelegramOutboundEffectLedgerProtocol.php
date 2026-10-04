<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Exceptions\TelegramAmbiguousResponseException;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Exceptions\TelegramTransportException;
use App\Models\TelegramOutboundEffect;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The durable ledger protocol owns claim, lease, retry, and terminal state
 * transitions. Effect-family adapters own source loading, rendering,
 * Telegram transport, and projection updates after the protocol accepts a
 * transition.
 */
class TelegramOutboundEffectLedgerProtocol
{
    public function __construct(
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramOutboundEffectPolicy $policy,
    ) {}

    /** @return Builder<TelegramOutboundEffect> */
    public function retryableQuery(?string $effectType = null): Builder
    {
        return $this->policy->applyRetryableConstraints(TelegramOutboundEffect::query(), $effectType);
    }

    /** @return Builder<TelegramOutboundEffect> */
    public function readyToDispatchQuery(?CarbonInterface $at = null, ?string $effectType = null): Builder
    {
        $at ??= now();

        return $this->retryableQuery($effectType)
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

            if (! $this->policy->canClaim($effect, $now)) {
                return null;
            }

            $claimMetadata = $this->policy->claimMetadata($effect);
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sending->value,
                'attempts' => $effect->attempts + 1,
                'next_attempt_at' => null,
                'lease_until' => $now->copy()->addSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))),
                'claim_token' => Str::random(64),
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $claimMetadata === []
                    ? $effect->outcome_metadata
                    : $this->mergeOutcomeMetadata($effect, $claimMetadata),
            ])->save();

            return $effect->fresh();
        });
    }

    /** @param array<string, mixed> $metadata */
    public function finalizeSent(string $effectId, string $claimToken, string $messageId, array $metadata = []): ?TelegramOutboundEffect
    {
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return null;
        }

        return DB::transaction(function () use ($effectId, $claimToken, $messageId, $metadata): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return null;
            }

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => now(),
                'unknown_at' => null,
                'failed_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, array_merge($metadata, ['telegram_response' => 'ok'])),
            ])->save();

            return $effect->fresh();
        });
    }

    /**
     * Resolve an ambiguous effect after an operator verified delivery outside
     * Telegram's API. This uses the same durable transition boundary as a
     * worker finalization, but does not require a claim token.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function finalizeOperatorSent(
        string $effectId,
        string $messageId,
        array $metadata = [],
    ): ?TelegramOutboundEffect {
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return null;
        }

        return DB::transaction(function () use ($effectId, $messageId, $metadata): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if ($effect === null || ! in_array($this->stateValue($effect), [
                TelegramOutboundEffectState::Unknown->value,
                TelegramOutboundEffectState::ManualReview->value,
            ], true)) {
                return null;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => $now,
                'manual_review_at' => $effect->manual_review_at ?? $now,
                'failed_at' => null,
                'unknown_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $metadata),
            ])->save();

            return $effect->fresh();
        });
    }

    public function finalizeFailed(
        string $effectId,
        string $claimToken,
        Throwable $exception,
        ?int $retryAfterSeconds = null,
    ): ?TelegramOutboundEffect {
        return DB::transaction(function () use ($effectId, $claimToken, $exception, $retryAfterSeconds): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return null;
            }

            $failureCode = $this->failureCode($exception);
            $attemptsExhausted = $this->policy->attemptsExhausted($effect);
            $terminal = $this->policy->isNonRetryableFailure($failureCode) || $attemptsExhausted;

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => now(),
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => $this->policy->nextAttemptAt($effect, $terminal, $retryAfterSeconds, now()),
                'last_error_code' => $this->policy->isNonRetryableFailure($failureCode)
                    ? $failureCode
                    : ($attemptsExhausted ? $this->policy->attemptsExhaustedCode($effect) : $failureCode),
                'last_error' => 'Telegram delivery failed before completion.',
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
            ])->save();

            return $effect->fresh();
        });
    }

    public function finalizeNotDeliverable(
        string $effectId,
        string $claimToken,
        string $reason,
        string $message = 'Telegram delivery was no longer eligible for delivery.',
    ): ?TelegramOutboundEffect {
        return DB::transaction(function () use ($effectId, $claimToken, $reason, $message): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return null;
            }

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => now(),
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => $reason,
                'last_error' => $message,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['reason' => $reason]),
            ])->save();

            return $effect->fresh();
        });
    }

    public function requestManualRetry(string $effectId): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if ($effect === null || ! $this->policy->canRequestManualRetry($effect)) {
                return null;
            }

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Pending->value,
                'next_attempt_at' => now(),
                'failed_at' => null,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->policy->manualRetryMetadata($effect)),
            ])->save();

            return $effect->fresh();
        });
    }

    public function finalizeUnknown(string $effectId, string $claimToken, Throwable $exception): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId, $claimToken, $exception): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return null;
            }

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Unknown->value,
                'lease_until' => null,
                'claim_token' => null,
                'next_attempt_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'unknown_at' => now(),
                'manual_review_at' => null,
                'last_error_code' => 'telegram_transport_ambiguous',
                'last_error' => 'Telegram delivery outcome is unknown and requires review.',
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
            ])->save();

            return $effect->fresh();
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

            foreach ($effects as $effect) {
                $effect->forceFill([
                    'state' => TelegramOutboundEffectState::Unknown->value,
                    'lease_until' => null,
                    'claim_token' => null,
                    'next_attempt_at' => null,
                    'unknown_at' => now(),
                    'manual_review_at' => null,
                    'last_error_code' => 'lease_expired',
                    'last_error' => 'Worker lease expired before Telegram delivery was finalized.',
                    'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['reason' => 'worker_lease_expired']),
                ])->save();
            }

            return $effects->count();
        });
    }

    public function isAmbiguous(Throwable $exception): bool
    {
        if ($exception instanceof TelegramOutboundPreSendException) {
            return false;
        }

        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if ($candidate instanceof TelegramAmbiguousResponseException) {
                return true;
            }

            if ($candidate instanceof TelegramTransportException || $candidate instanceof ConnectionException) {
                return true;
            }

            if ($candidate instanceof RequestException) {
                $status = $candidate->response?->status();

                if ($status === null || $status === 408 || $status >= 500) {
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

    public function retryAfterSeconds(Throwable $exception): ?int
    {
        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if (! $candidate instanceof RequestException || $candidate->response?->status() !== 429) {
                continue;
            }

            $retryAfter = data_get($candidate->response->json(), 'parameters.retry_after')
                ?? $candidate->response->header('Retry-After');
            if (! is_numeric($retryAfter)) {
                return 1;
            }

            return min(300, max(1, (int) $retryAfter));
        }

        return null;
    }

    public function isTerminalFailure(TelegramOutboundEffect $effect): bool
    {
        return $this->policy->isTerminalFailure($effect);
    }

    public function isDispatchable(TelegramOutboundEffect $effect): bool
    {
        return $this->policy->isDispatchable($effect, now());
    }

    /**
     * Return whether every receipt unit has reached the family completion
     * state for the group. The policy owns version-specific exceptions, so
     * callers do not inspect receipt states or ordering themselves.
     */
    public function isReceiptGroupComplete(?string $effectGroupKey): bool
    {
        if ($effectGroupKey === null || $effectGroupKey === '') {
            return false;
        }

        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('effect_group_key', $effectGroupKey)
            ->orderBy('sequence')
            ->get();

        return $effects->isNotEmpty()
            && $effects->every(fn (TelegramOutboundEffect $effect): bool => $this->policy->isReceiptEffectComplete($effect));
    }

    /**
     * Return the first dispatchable receipt unit after the given sequence.
     *
     * Sequence zero starts at the beginning of a group. Receipt ordering and
     * the legacy invoice-photo exception are evaluated by the family policy;
     * callers do not select or filter receipt states themselves.
     */
    public function nextReceiptEffect(?string $effectGroupKey, int $afterSequence = 0): ?TelegramOutboundEffect
    {
        if ($effectGroupKey === null || $effectGroupKey === '') {
            return null;
        }

        $at = now();
        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('effect_group_key', $effectGroupKey)
            ->where('sequence', '>', max(0, $afterSequence))
            ->orderBy('sequence')
            ->get();

        foreach ($effects as $candidate) {
            if ($this->policy->canSelectReceiptSuccessor($candidate, $at)) {
                return $candidate;
            }
        }

        return null;
    }

    private function claimMatches(?TelegramOutboundEffect $effect, string $claimToken): bool
    {
        return $effect !== null
            && $effect->state === TelegramOutboundEffectState::Sending
            && $effect->claim_token !== null
            && hash_equals((string) $effect->claim_token, $claimToken);
    }

    /** @param array<string, mixed> $updates */
    private function mergeOutcomeMetadata(TelegramOutboundEffect $effect, array $updates): array
    {
        $existing = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return array_merge($existing, $updates);
    }

    private function failureCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if ($exception instanceof TelegramOutboundPreSendException
            && (str_contains($message, 'no longer renderable')
                || str_contains($message, 'paid invoice rendering failed before send'))) {
            return 'receipt_render_changed';
        }

        if ($exception instanceof RequestException && $exception->response !== null) {
            return 'telegram_http_'.(int) $exception->response->status();
        }

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

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }
}
