<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pins the first credential-bearing buyer receipt message for an Order.
 *
 * Pinning is a convenience side effect, not receipt delivery state. A failed
 * pin therefore never changes the receipt ledger outcome or fulfillment.
 *
 * Delivery, successor dispatch and the admin wakeup never wait for this work:
 * a queued job attempts the pin, and `reconcileMissingPins()` repairs a lost
 * wakeup or a failed attempt. The claim token serializes competing attempts so
 * exactly one canonical message can be pinned per Order even when delivery and
 * recovery race.
 */
final class TelegramCredentialPinService
{
    /**
     * A claim outlives the Bot API timeout (10 s) so a live request is never
     * stolen. A worker that dies mid-call leaves the claim retryable after
     * this lease.
     */
    public const CLAIM_LEASE_SECONDS = 60;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function pin(Order $order, TelegramOutboundEffect $effect): bool
    {
        $startedAt = DogfoodTiming::now();
        $startedIso = now()->toISOString();
        $claimToken = null;
        $outcome = 'ineligible';

        try {
            if (! $this->eligible($order, $effect)) {
                return false;
            }

            $claimToken = Str::uuid()->toString();
            $claimedAt = now();
            $claimed = Order::query()
                ->whereKey($order->getKey())
                ->whereNull('telegram_credential_pinned_at')
                ->whereNull('telegram_credential_message_id')
                ->where(function ($query) use ($claimedAt): void {
                    $query->whereNull('telegram_credential_pin_claim_token')
                        ->orWhereNull('telegram_credential_pin_claimed_at')
                        ->orWhere(
                            'telegram_credential_pin_claimed_at',
                            '<',
                            $claimedAt->copy()->subSeconds(self::CLAIM_LEASE_SECONDS),
                        );
                })
                ->update([
                    'telegram_credential_pin_claim_token' => $claimToken,
                    'telegram_credential_pin_claimed_at' => $claimedAt,
                    'updated_at' => $claimedAt,
                ]);

            if ($claimed !== 1) {
                $outcome = 'claim_contended';

                return false;
            }

            $messageId = (string) $effect->telegram_message_id;
            $chatId = trim((string) $effect->telegram_chat_id);
            $this->bot->pinChatMessage($chatId, $messageId, disableNotification: true);

            $pinnedAt = now();
            $updated = Order::query()
                ->whereKey($order->getKey())
                ->where('telegram_credential_pin_claim_token', $claimToken)
                ->whereNull('telegram_credential_pinned_at')
                ->whereNull('telegram_credential_message_id')
                ->update([
                    'telegram_credential_message_chat_id' => $chatId,
                    'telegram_credential_message_id' => (int) $messageId,
                    'telegram_credential_pinned_at' => $pinnedAt,
                    'telegram_credential_pin_claim_token' => null,
                    'telegram_credential_pin_claimed_at' => null,
                    'updated_at' => $pinnedAt,
                ]);
            if ($updated !== 1) {
                $outcome = 'claim_lost';

                return false;
            }

            $order->setAttribute('telegram_credential_message_chat_id', $chatId);
            $order->setAttribute('telegram_credential_message_id', (int) $messageId);
            $order->setAttribute('telegram_credential_pinned_at', $pinnedAt);
            $order->setAttribute('telegram_credential_pin_claim_token', null);
            $order->setAttribute('telegram_credential_pin_claimed_at', null);
            $outcome = 'pinned';

            return true;
        } catch (Throwable $exception) {
            $outcome = 'failed';
            $this->releaseClaim($claimToken);
            report($exception);

            return false;
        } finally {
            $this->observePinAttempt($order, $effect, $outcome, $startedAt, $startedIso);
        }
    }

    /**
     * Recover a successful receipt whose worker stopped before pinning it.
     * Credential-bearing receipt metadata contains no credential values.
     */
    public function reconcileMissingPins(int $chunk = 100): int
    {
        $effects = app(TelegramOutboundRecoveryPage::class)->load(
            'missing_credential_pins',
            TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                ->where('state', TelegramOutboundEffectState::Sent->value)
                ->whereNotNull('telegram_message_id')
                ->whereJsonContains('outcome_metadata', ['contains_credentials' => true]),
            max(1, $chunk),
        )->sortBy([
            ['source_id', 'asc'],
            ['sequence', 'asc'],
        ])->values();
        $orders = Order::query()
            ->with('customer')
            ->whereIn('id', $effects->pluck('source_id')->map(static fn (mixed $id): string => (string) $id)->all())
            ->whereNull('telegram_credential_pinned_at')
            ->whereNull('telegram_credential_message_id')
            ->get()
            ->keyBy(static fn (Order $order): string => (string) $order->getKey());
        $pinned = 0;

        foreach ($effects as $effect) {
            $order = $orders->get((string) $effect->source_id);
            if ($order instanceof Order && $this->pin($order, $effect)) {
                $pinned++;
            }
        }

        return $pinned;
    }

    private function releaseClaim(?string $claimToken): void
    {
        if ($claimToken === null || $claimToken === '') {
            return;
        }

        try {
            Order::query()
                ->where('telegram_credential_pin_claim_token', $claimToken)
                ->update([
                    'telegram_credential_pin_claim_token' => null,
                    'telegram_credential_pin_claimed_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function eligible(Order $order, TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $customerChatId = trim((string) $order->customer?->telegram_id);
        $effectChatId = trim((string) $effect->telegram_chat_id);
        $messageId = (string) $effect->telegram_message_id;

        return $order->telegram_credential_pinned_at === null
            && $order->telegram_credential_message_id === null
            && $effect->source_type === Order::class
            && (string) $effect->source_id === (string) $order->getKey()
            && $effect->effect_type === TelegramOutboundEffectType::ReceiptCredentials->value
            && $effect->state === TelegramOutboundEffectState::Sent
            && ($metadata['contains_credentials'] ?? false) === true
            && $effect->sent_at !== null
            && $customerChatId !== ''
            && $customerChatId === $effectChatId
            && preg_match('/\A[1-9][0-9]*\z/D', $effectChatId) === 1
            && $this->responseValidator->isValidMessageId($messageId);
    }

    private function observePinAttempt(
        Order $order,
        TelegramOutboundEffect $effect,
        string $outcome,
        int $startedAt,
        string $startedIso,
    ): void {
        if (! DogfoodTiming::enabled()) {
            return;
        }

        DogfoodTiming::log('dogfood.telegram.credential_pin_finished', [
            'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
            'effect_correlation_id' => DogfoodTiming::correlation($effect->getKey()),
            'sequence' => (int) $effect->sequence,
            'pin_started_at' => $startedIso,
            'pin_finished_at' => now()->toISOString(),
            'pin_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            'pin_outcome' => $outcome,
            'pinned' => $outcome === 'pinned',
        ]);
    }
}
