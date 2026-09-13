<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramOutboundEffect;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Family policy for the durable Telegram Outbound Effect ledger.
 *
 * The protocol owns state transitions. This object only supplies the
 * family-specific limits and ordering rules needed to make those transitions
 * uniform without putting family delivery flows in the protocol.
 */
final class TelegramOutboundEffectPolicy
{
    public const RECEIPT_MAX_ATTEMPTS = 3;

    public const RECEIPT_TXT_DOWNLOAD_MAX_ATTEMPTS = 3;

    public const RECEIPT_ACTIONS_MAX_ATTEMPTS = 3;

    public const SALES_PROOF_MAX_ATTEMPTS = 3;

    public const ADMIN_BROADCAST_MAX_ATTEMPTS = 5;

    public const STOCK_NOTIFICATION_MAX_ATTEMPTS = 5;

    public const PRICE_CHANGE_MAX_ATTEMPTS = 5;

    public const PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS = 1;

    public const BALANCE_TOP_UP_MAX_ATTEMPTS = 3;

    /** @var list<string> */
    private const NON_RETRYABLE_FAILURE_CODES = [
        'checkout_expired',
        'checkout_not_pending',
        'checkout_payment_missing',
        'checkout_payment_not_pending',
        'receipt_not_eligible',
        'receipt_render_changed',
        'balance_top_up_missing',
        'balance_top_up_not_payable',
        'balance_top_up_expired',
        'balance_top_up_receipt_missing',
        'balance_top_up_not_paid',
        'balance_owner_compensation_missing',
        'balance_owner_compensation_not_eligible',
        'balance_top_up_owner_alert_not_eligible',
        'balance_top_up_proof_not_eligible',
        'receipt_txt_credentials_missing',
        'receipt_txt_render_failed',
        'receipt_actions_not_eligible',
        'receipt_actions_render_failed',
        'admin_target_missing',
        'admin_notification_not_eligible',
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

    /** @var array<string, int> */
    private const MAX_ATTEMPTS = [
        TelegramOutboundEffectType::ReceiptCredentials->value => self::RECEIPT_MAX_ATTEMPTS,
        TelegramOutboundEffectType::ReceiptTxtDownload->value => self::RECEIPT_TXT_DOWNLOAD_MAX_ATTEMPTS,
        TelegramOutboundEffectType::ReceiptOrderActions->value => self::RECEIPT_ACTIONS_MAX_ATTEMPTS,
        TelegramOutboundEffectType::SalesProofPost->value => self::SALES_PROOF_MAX_ATTEMPTS,
        TelegramOutboundEffectType::AdminBroadcastRecipient->value => self::ADMIN_BROADCAST_MAX_ATTEMPTS,
        TelegramOutboundEffectType::StockNotificationRecipient->value => self::STOCK_NOTIFICATION_MAX_ATTEMPTS,
        TelegramOutboundEffectType::PriceChangeNotificationRecipient->value => self::PRICE_CHANGE_MAX_ATTEMPTS,
        TelegramOutboundEffectType::PriceChangeStoreChannel->value => self::PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS,
        TelegramOutboundEffectType::BalanceTopUpInstructions->value => self::BALANCE_TOP_UP_MAX_ATTEMPTS,
        TelegramOutboundEffectType::BalanceTopUpReceipt->value => self::BALANCE_TOP_UP_MAX_ATTEMPTS,
        TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value => self::BALANCE_TOP_UP_MAX_ATTEMPTS,
        TelegramOutboundEffectType::BalanceTopUpChannelProof->value => self::BALANCE_TOP_UP_MAX_ATTEMPTS,
        TelegramOutboundEffectType::OwnerCompensationNotification->value => self::BALANCE_TOP_UP_MAX_ATTEMPTS,
    ];

    /** @var array<string, string> */
    private const ATTEMPTS_EXHAUSTED_CODES = [
        TelegramOutboundEffectType::ReceiptCredentials->value => 'receipt_attempts_exhausted',
        TelegramOutboundEffectType::ReceiptTxtDownload->value => 'receipt_txt_download_attempts_exhausted',
        TelegramOutboundEffectType::ReceiptOrderActions->value => 'receipt_actions_attempts_exhausted',
        TelegramOutboundEffectType::AdminBroadcastRecipient->value => 'broadcast_recipient_attempts_exhausted',
        TelegramOutboundEffectType::StockNotificationRecipient->value => 'stock_notification_attempts_exhausted',
        TelegramOutboundEffectType::PriceChangeNotificationRecipient->value => 'price_change_recipient_attempts_exhausted',
        TelegramOutboundEffectType::PriceChangeStoreChannel->value => 'price_change_store_channel_attempts_exhausted',
        TelegramOutboundEffectType::BalanceTopUpInstructions->value => 'balance_top_up_attempts_exhausted',
        TelegramOutboundEffectType::BalanceTopUpReceipt->value => 'balance_top_up_receipt_attempts_exhausted',
        TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value => 'balance_top_up_owner_alert_attempts_exhausted',
        TelegramOutboundEffectType::BalanceTopUpChannelProof->value => 'balance_top_up_channel_proof_attempts_exhausted',
        TelegramOutboundEffectType::OwnerCompensationNotification->value => 'balance_owner_compensation_attempts_exhausted',
    ];

    /** @return Builder<TelegramOutboundEffect> */
    public function applyRetryableConstraints(Builder $query): Builder
    {
        $query
            ->where(function (Builder $state): void {
                $state
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
            ->where(function (Builder $attempts): void {
                $attempts->whereNotIn('effect_type', array_keys(self::MAX_ATTEMPTS));

                foreach (self::MAX_ATTEMPTS as $effectType => $maximum) {
                    $attempts->orWhere(function (Builder $family) use ($effectType, $maximum): void {
                        $family->where('effect_type', $effectType)
                            ->where(function (Builder $available) use ($effectType, $maximum): void {
                                $available->where('attempts', '<', $maximum);

                                if (in_array($effectType, [
                                    TelegramOutboundEffectType::SalesProofPost->value,
                                    TelegramOutboundEffectType::BalanceTopUpInstructions->value,
                                ], true)) {
                                    $available->orWhereJsonContains('outcome_metadata', ['manual_retry_requested' => true]);
                                }
                            });
                    });
                }
            })
            ->where(function (Builder $receipt): void {
                $receipt
                    ->where('effect_type', '<>', TelegramOutboundEffectType::ReceiptCredentials->value)
                    ->orWhere(function (Builder $ordered): void {
                        $ordered
                            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                            ->where(function (Builder $sequence): void {
                                $sequence
                                    ->where('sequence', '<=', 1)
                                    ->orWhereNotExists(function ($predecessor): void {
                                        $predecessor
                                            ->selectRaw('1')
                                            ->from('telegram_outbound_effects as receipt_predecessors')
                                            ->whereColumn('receipt_predecessors.effect_group_key', 'telegram_outbound_effects.effect_group_key')
                                            ->whereColumn('receipt_predecessors.sequence', '<', 'telegram_outbound_effects.sequence')
                                            ->where(function ($blocking): void {
                                                $blocking
                                                    ->where('receipt_predecessors.state', '<>', TelegramOutboundEffectState::Sent->value)
                                                    ->where(function ($resolvedInvoice): void {
                                                        $resolvedInvoice
                                                            ->where('receipt_predecessors.state', 'not in', [
                                                                TelegramOutboundEffectState::Failed->value,
                                                                TelegramOutboundEffectState::Unknown->value,
                                                            ])
                                                            ->orWhere('receipt_predecessors.telegram_method', '<>', 'sendPhoto')
                                                            ->orWhereNull('receipt_predecessors.telegram_method')
                                                            ->orWhere('receipt_predecessors.template_version', '<>', TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE)
                                                            ->orWhereNull('receipt_predecessors.template_version');
                                                    });
                                            });
                                    });
                            });
                    });
            });

        return $query;
    }

    public function canClaim(TelegramOutboundEffect $effect, CarbonInterface $at): bool
    {
        if (! in_array($this->stateValue($effect), [
            TelegramOutboundEffectState::Pending->value,
            TelegramOutboundEffectState::Failed->value,
        ], true) || $this->isNonRetryableFailure($effect->last_error_code)) {
            return false;
        }

        if ($this->attemptsExhausted($effect) && ! $this->manualRetryRequested($effect)) {
            return false;
        }

        return ($effect->next_attempt_at === null || $effect->next_attempt_at->lte($at))
            && $this->orderedPredecessorIsResolved($effect);
    }

    public function canSelectReceiptSuccessor(
        TelegramOutboundEffect $effect,
        CarbonInterface $at,
    ): bool {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptCredentials->value
            || ! in_array($this->stateValue($effect), [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ], true)
            || $this->isLegacyInvoiceOrderingException($effect)) {
            return false;
        }

        return $this->canClaim($effect, $at);
    }

    public function isReceiptEffectComplete(TelegramOutboundEffect $effect): bool
    {
        return $this->stateValue($effect) === TelegramOutboundEffectState::Sent->value
            || $this->isLegacyInvoiceOrderingException($effect);
    }

    /** @return array<string, mixed> */
    public function claimMetadata(TelegramOutboundEffect $effect): array
    {
        return $this->supportsManualRetry($effect)
            ? ['manual_retry_requested' => false]
            : [];
    }

    public function isTerminalFailure(TelegramOutboundEffect $effect): bool
    {
        return $this->stateValue($effect) === TelegramOutboundEffectState::Failed->value
            && ($this->isNonRetryableFailure($effect->last_error_code) || $this->attemptsExhausted($effect));
    }

    public function isDispatchable(TelegramOutboundEffect $effect, CarbonInterface $at): bool
    {
        return $this->canClaim($effect, $at);
    }

    public function attemptsExhausted(TelegramOutboundEffect $effect): bool
    {
        $maximum = self::MAX_ATTEMPTS[$effect->effect_type] ?? null;

        return $maximum !== null && (int) $effect->attempts >= $maximum;
    }

    public function attemptsExhaustedCode(TelegramOutboundEffect $effect): string
    {
        return self::ATTEMPTS_EXHAUSTED_CODES[$effect->effect_type] ?? 'telegram_attempts_exhausted';
    }

    public function isNonRetryableFailure(?string $failureCode): bool
    {
        return $failureCode !== null && in_array($failureCode, self::NON_RETRYABLE_FAILURE_CODES, true);
    }

    public function nextAttemptAt(
        TelegramOutboundEffect $effect,
        bool $terminal,
        ?int $retryAfterSeconds,
        CarbonInterface $at,
    ): ?CarbonInterface {
        if ($terminal) {
            return null;
        }

        if ($effect->effect_type === TelegramOutboundEffectType::SalesProofPost->value) {
            return $at;
        }

        return $at->copy()->addSeconds($retryAfterSeconds ?? $this->retryDelaySeconds($effect, (int) $effect->attempts));
    }

    public function retryDelaySeconds(TelegramOutboundEffect $effect, int $attempts): int
    {
        if (in_array($effect->effect_type, [
            TelegramOutboundEffectType::StockNotificationRecipient->value,
            TelegramOutboundEffectType::PriceChangeNotificationRecipient->value,
        ], true)) {
            return 30;
        }

        return match (true) {
            $attempts <= 1 => 5,
            $attempts === 2 => 30,
            default => 120,
        };
    }

    public function manualRetryRequested(TelegramOutboundEffect $effect): bool
    {
        return $this->supportsManualRetry($effect)
            && (($effect->outcome_metadata['manual_retry_requested'] ?? false) === true);
    }

    public function canRequestManualRetry(TelegramOutboundEffect $effect): bool
    {
        return $this->supportsManualRetry($effect)
            && $this->stateValue($effect) === TelegramOutboundEffectState::Failed->value
            && ! $this->isNonRetryableFailure($effect->last_error_code);
    }

    /** @return array<string, mixed> */
    public function manualRetryMetadata(?TelegramOutboundEffect $effect = null): array
    {
        $metadata = [
            'manual_retry_requested' => true,
        ];
        if ($effect?->effect_type === TelegramOutboundEffectType::SalesProofPost->value) {
            $metadata['delivery_context'] = 'sales_proof';
        }

        return $metadata;
    }

    private function supportsManualRetry(TelegramOutboundEffect $effect): bool
    {
        return in_array($effect->effect_type, [
            TelegramOutboundEffectType::SalesProofPost->value,
            TelegramOutboundEffectType::BalanceTopUpInstructions->value,
        ], true);
    }

    private function orderedPredecessorIsResolved(TelegramOutboundEffect $effect): bool
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptCredentials->value
            || $effect->effect_group_key === null
            || (int) $effect->sequence <= 1) {
            return true;
        }

        return ! TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '<', $effect->sequence)
            ->where(function (Builder $blocking): void {
                $blocking
                    ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
                    ->where(function (Builder $resolvedInvoice): void {
                        $resolvedInvoice
                            ->where('state', 'not in', [
                                TelegramOutboundEffectState::Failed->value,
                                TelegramOutboundEffectState::Unknown->value,
                            ])
                            ->orWhere('telegram_method', '<>', 'sendPhoto')
                            ->orWhereNull('telegram_method')
                            ->orWhere('template_version', '<>', TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE)
                            ->orWhereNull('template_version');
                    });
            })
            ->exists();
    }

    private function isLegacyInvoiceOrderingException(TelegramOutboundEffect $effect): bool
    {
        return (int) $effect->sequence === 1
            && in_array($this->stateValue($effect), [
                TelegramOutboundEffectState::Failed->value,
                TelegramOutboundEffectState::Unknown->value,
            ], true)
            && $effect->telegram_method === 'sendPhoto'
            && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE;
    }

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }
}
