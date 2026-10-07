<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use Throwable;

/**
 * Pins the first credential-bearing buyer receipt message for an Order.
 *
 * Pinning is a convenience side effect, not receipt delivery state. A failed
 * pin therefore never changes the receipt ledger outcome or fulfillment.
 */
final class TelegramCredentialPinService
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function pin(Order $order, TelegramOutboundEffect $effect): bool
    {
        try {
            if (! $this->eligible($order, $effect)) {
                return false;
            }

            $messageId = (string) $effect->telegram_message_id;
            $chatId = trim((string) $effect->telegram_chat_id);
            $this->bot->pinChatMessage($chatId, $messageId, disableNotification: true);

            $pinnedAt = now();
            $updated = Order::query()
                ->whereKey($order->getKey())
                ->whereNull('telegram_credential_pinned_at')
                ->whereNull('telegram_credential_message_id')
                ->update([
                    'telegram_credential_message_chat_id' => $chatId,
                    'telegram_credential_message_id' => (int) $messageId,
                    'telegram_credential_pinned_at' => $pinnedAt,
                    'updated_at' => $pinnedAt,
                ]);
            if ($updated !== 1) {
                return false;
            }

            $order->setAttribute('telegram_credential_message_chat_id', $chatId);
            $order->setAttribute('telegram_credential_message_id', (int) $messageId);
            $order->setAttribute('telegram_credential_pinned_at', $pinnedAt);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
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
        );
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

    private function eligible(Order $order, TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $customerChatId = trim((string) $order->customer?->telegram_id);
        $effectChatId = trim((string) $effect->telegram_chat_id);
        $messageId = (string) $effect->telegram_message_id;

        return $order->telegram_credential_pinned_at === null
            && $order->telegram_credential_message_id === null
            && $effect->effect_type === TelegramOutboundEffectType::ReceiptCredentials->value
            && $effect->state === TelegramOutboundEffectState::Sent
            && ($metadata['contains_credentials'] ?? false) === true
            && $effect->sent_at !== null
            && $customerChatId !== ''
            && $customerChatId === $effectChatId
            && preg_match('/\A[1-9][0-9]*\z/D', $effectChatId) === 1
            && $this->responseValidator->isValidMessageId($messageId);
    }
}
