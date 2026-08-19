<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Admin review is an adapter around the protocol, not a second state machine.
 * It records operator evidence, applies the family read projection, and
 * dispatches the next ordered receipt unit when appropriate.
 */
final class TelegramOutboundEffectReviewService
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramOutboundEffectReviewProjection $projection,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function markSent(
        TelegramOutboundEffect $effect,
        string $messageId,
        ?User $actor = null,
        ?string $ip = null,
    ): bool {
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return false;
        }

        return DB::transaction(function () use ($effect, $messageId, $actor, $ip): bool {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->find($effect->getKey());
            if ($locked === null || ! $this->isReviewable($locked)) {
                return false;
            }

            $oldState = $this->stateValue($locked);
            $now = now();
            $resolved = $this->ledger->finalizeOperatorSent((string) $locked->getKey(), $messageId, [
                'resolution' => 'operator_marked_sent',
                'resolution_actor_id' => $actor?->getKey(),
                'resolution_at' => $now->toIso8601String(),
            ]);
            if ($resolved === null) {
                return false;
            }

            $this->projection->synchronizeSent($resolved, $messageId, $now);
            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.marked_sent',
                $resolved,
                ['state' => $oldState],
                [
                    'state' => TelegramOutboundEffectState::Sent->value,
                    'telegram_message_id' => $messageId,
                    'resolution' => 'operator_marked_sent',
                ],
                $actor,
                $ip,
            );

            if ($resolved->effect_type === TelegramOutboundEffectType::ReceiptCredentials->value) {
                $next = $this->nextReceiptEffect($resolved);
                if ($next !== null) {
                    $this->dispatcher->dispatch($next);
                } elseif ($this->isExplicitReceiptEffect($resolved) && $this->receiptGroupComplete($resolved)) {
                    DeleteBuyerCheckoutMessages::dispatch((string) $resolved->source_id, now()->toISOString())
                        ->onQueue('notifications')
                        ->afterCommit();
                }
            }

            return true;
        });
    }

    private function isReviewable(TelegramOutboundEffect $effect): bool
    {
        return in_array($this->stateValue($effect), [
            TelegramOutboundEffectState::Unknown->value,
            TelegramOutboundEffectState::ManualReview->value,
        ], true);
    }

    private function nextReceiptEffect(TelegramOutboundEffect $effect): ?TelegramOutboundEffect
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptCredentials->value
            || $effect->effect_group_key === null) {
            return null;
        }

        return TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '>', $effect->sequence)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->first(fn (TelegramOutboundEffect $candidate): bool => $this->ledger->isDispatchable($candidate));
    }

    private function isExplicitReceiptEffect(TelegramOutboundEffect $effect): bool
    {
        return ($effect->outcome_metadata['delivery_context'] ?? null) === 'explicit_resend';
    }

    private function receiptGroupComplete(TelegramOutboundEffect $effect): bool
    {
        return $effect->effect_group_key !== null
            && ! TelegramOutboundEffect::query()
                ->where('effect_group_key', $effect->effect_group_key)
                ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
                ->exists();
    }

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }
}
