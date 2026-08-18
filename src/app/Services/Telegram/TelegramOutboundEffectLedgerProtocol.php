<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
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
    /** @var list<string> */
    private const NON_RETRYABLE_FAILURE_CODES = [
        'checkout_expired',
        'checkout_not_pending',
        'checkout_payment_missing',
        'checkout_payment_not_pending',
        'receipt_not_eligible',
        'receipt_render_changed',
        'admin_notification_not_eligible',
        'admin_target_missing',
        'proof_not_eligible',
        'proof_attachment_missing',
        'proof_already_forwarded',
        'proof_disabled',
        'proof_target_missing',
        'telegram_http_400',
        'telegram_http_403',
        'broadcast_recipient_attempts_exhausted',
        'broadcast_recipient_terminal_failure',
        'stock_notification_recipient_missing',
        'stock_notification_attempts_exhausted',
        'stock_notification_terminal_failure',
        'price_change_recipient_missing',
        'price_change_event_missing',
        'price_change_recipient_attempts_exhausted',
        'price_change_store_channel_attempts_exhausted',
    ];

    /** @return Builder<TelegramOutboundEffect> */
    public function readyToDispatchQuery(?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return TelegramOutboundEffect::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('state', TelegramOutboundEffectState::Pending->value)
                    ->orWhere(function (Builder $failed): void {
                        $failed
                            ->where('state', TelegramOutboundEffectState::Failed->value)
                            ->where(function (Builder $error): void {
                                $error
                                    ->whereNull('last_error_code')
                                    ->orWhereNotIn('last_error_code', self::NON_RETRYABLE_FAILURE_CODES);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                    ->orWhere(function (Builder $salesProof): void {
                        $salesProof->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                            ->where(function (Builder $attempts): void {
                                $attempts
                                    ->where('attempts', '<', TelegramOutboundEffectService::SALES_PROOF_MAX_ATTEMPTS)
                                    ->orWhereJsonContains('outcome_metadata', ['manual_retry_requested' => true]);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
                    ->orWhere('attempts', '<', TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
                    ->orWhere('attempts', '<', TelegramOutboundEffectService::STOCK_NOTIFICATION_MAX_ATTEMPTS);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
                    ->orWhere('attempts', '<', TelegramOutboundEffectService::PRICE_CHANGE_MAX_ATTEMPTS);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL)
                    ->orWhere('attempts', '<', TelegramOutboundEffectService::PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $at);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
                    ->orWhere(function (Builder $receipt): void {
                        $receipt
                            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
                            ->where(function (Builder $sequence): void {
                                $sequence
                                    ->where('sequence', '<=', 1)
                                    ->orWhereNotExists(function ($predecessor): void {
                                        $predecessor
                                            ->selectRaw('1')
                                            ->from('telegram_outbound_effects as receipt_predecessors')
                                            ->whereColumn('receipt_predecessors.effect_group_key', 'telegram_outbound_effects.effect_group_key')
                                            ->whereColumn('receipt_predecessors.sequence', '<', 'telegram_outbound_effects.sequence')
                                            ->where('receipt_predecessors.state', '<>', TelegramOutboundEffectState::Sent->value);
                                    });
                            });
                    });
            });
    }

    public function claim(string $effectId): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effectId);
            $now = now();

            if (! $this->isClaimable($effect, $now) || ! $this->receiptEffectIsOrderedReady($effect)) {
                return null;
            }

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sending->value,
                'attempts' => $effect->attempts + 1,
                'next_attempt_at' => null,
                'lease_until' => $now->copy()->addSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))),
                'claim_token' => Str::random(64),
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $effect->effect_type === TelegramOutboundEffectService::TYPE_SALES_PROOF
                    ? $this->mergeOutcomeMetadata($effect, ['manual_retry_requested' => false])
                    : $effect->outcome_metadata,
            ])->save();

            return $effect->fresh();
        });
    }

    public function finalizeSent(string $effectId, string $claimToken, string $messageId): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId, $claimToken, $messageId): ?TelegramOutboundEffect {
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
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['telegram_response' => 'ok']),
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
            $attemptsExhausted = $this->effectAttemptsExhausted($effect);
            $terminal = in_array($failureCode, self::NON_RETRYABLE_FAILURE_CODES, true) || $attemptsExhausted;

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => now(),
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => $terminal
                    ? null
                    : ($effect->effect_type === TelegramOutboundEffectService::TYPE_SALES_PROOF
                        ? ((int) $effect->attempts < TelegramOutboundEffectService::SALES_PROOF_MAX_ATTEMPTS ? now() : null)
                        : now()->copy()->addSeconds($retryAfterSeconds ?? $this->retryDelaySeconds($effect->attempts))),
                'last_error_code' => in_array($failureCode, self::NON_RETRYABLE_FAILURE_CODES, true)
                    ? $failureCode
                    : ($attemptsExhausted ? $this->attemptsExhaustedCode($effect) : $failureCode),
                'last_error' => 'Telegram delivery failed before completion.',
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
            ])->save();

            return $effect->fresh();
        });
    }

    public function finalizeNotDeliverable(string $effectId, string $claimToken, string $reason, string $message): ?TelegramOutboundEffect
    {
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

    public function isTerminalFailure(TelegramOutboundEffect $effect): bool
    {
        if ($this->stateValue($effect) !== TelegramOutboundEffectState::Failed->value) {
            return false;
        }

        return in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)
            || $this->effectAttemptsExhausted($effect);
    }

    public function isDispatchable(TelegramOutboundEffect $effect): bool
    {
        if (! in_array($this->stateValue($effect), [
            TelegramOutboundEffectState::Pending->value,
            TelegramOutboundEffectState::Failed->value,
        ], true)) {
            return false;
        }

        if (in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)) {
            return false;
        }

        if ($effect->effect_type === TelegramOutboundEffectService::TYPE_SALES_PROOF
            && (int) $effect->attempts >= TelegramOutboundEffectService::SALES_PROOF_MAX_ATTEMPTS) {
            return false;
        }

        if ($effect->effect_type === TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST
            && (int) $effect->attempts >= TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS) {
            return false;
        }

        if ($this->effectAttemptsExhausted($effect)) {
            return false;
        }

        return $effect->next_attempt_at === null || $effect->next_attempt_at->lte(now());
    }

    private function isClaimable(TelegramOutboundEffect $effect, CarbonInterface $at): bool
    {
        if (! in_array($effect->state, [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Failed], true)
            || in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)) {
            return false;
        }

        if ($effect->effect_type === TelegramOutboundEffectService::TYPE_SALES_PROOF
            && (int) $effect->attempts >= TelegramOutboundEffectService::SALES_PROOF_MAX_ATTEMPTS
            && (($effect->outcome_metadata['manual_retry_requested'] ?? false) !== true)) {
            return false;
        }

        if ($effect->effect_type === TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST
            && (int) $effect->attempts >= TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS) {
            return false;
        }

        if ($this->effectAttemptsExhausted($effect)) {
            return false;
        }

        return $effect->next_attempt_at === null || $effect->next_attempt_at->lte($at);
    }

    private function receiptEffectIsOrderedReady(TelegramOutboundEffect $effect): bool
    {
        if ($effect->effect_type !== TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS
            || $effect->effect_group_key === null
            || (int) $effect->sequence <= 1) {
            return true;
        }

        return ! TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '<', $effect->sequence)
            ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
            ->exists();
    }

    private function effectAttemptsExhausted(TelegramOutboundEffect $effect): bool
    {
        $maximum = match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST => TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS,
            TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION => TelegramOutboundEffectService::STOCK_NOTIFICATION_MAX_ATTEMPTS,
            TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION => TelegramOutboundEffectService::PRICE_CHANGE_MAX_ATTEMPTS,
            TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL => TelegramOutboundEffectService::PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS,
            default => null,
        };

        return $maximum !== null && (int) $effect->attempts >= $maximum;
    }

    private function attemptsExhaustedCode(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST => 'broadcast_recipient_attempts_exhausted',
            TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION => 'stock_notification_attempts_exhausted',
            TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION => 'price_change_recipient_attempts_exhausted',
            TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL => 'price_change_store_channel_attempts_exhausted',
            default => 'telegram_attempts_exhausted',
        };
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
        if ($exception instanceof TelegramOutboundPreSendException
            && str_contains(strtolower($exception->getMessage()), 'no longer renderable')) {
            return 'receipt_render_changed';
        }

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

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }
}
