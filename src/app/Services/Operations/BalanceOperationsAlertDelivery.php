<?php

namespace App\Services\Operations;

use App\Enums\BalanceOperationMetric;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use InvalidArgumentException;
use Throwable;

/**
 * Delivery adapter for durable balance operations alerts (#558). The rendered
 * message is baked into the effect at materialization time, so a later episode
 * update or resolution can never change what an already-pending effect sends.
 * The adapter owns transport only: claim, lease, retry and ambiguity come from
 * the shared outbound ledger protocol, and a failed delivery never changes
 * money or the episode condition.
 */
final class BalanceOperationsAlertDelivery
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responses,
        private readonly BalanceOperationEventRecorder $metrics,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::BalanceOperationsAlert->value) {
            throw new InvalidArgumentException('Unsupported balance operations alert effect.');
        }

        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }

        try {
            $metadata = is_array($claimed->outcome_metadata) ? $claimed->outcome_metadata : [];
            $message = $metadata['message'] ?? null;
            if (! is_string($message) || trim($message) === '') {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'balance_operations_alert_missing');

                return;
            }

            $chatId = (string) $claimed->telegram_chat_id;
            if (preg_match('/^[1-9][0-9]*$/', $chatId) !== 1) {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'admin_target_missing');

                return;
            }

            $response = $this->bot->sendMessage($chatId, $message, options: ['parse_mode' => null]);
            $messageId = $this->responses->messageIdFromResponse($response, 'Balance operations alert');
            $this->ledger->finalizeSent((string) $claimed->id, $claimed->claim_token, $messageId);
            $this->metrics->record(BalanceOperationMetric::OutboundDeliveryOutcome, 'sent');
        } catch (Throwable $error) {
            if ($this->ledger->isAmbiguous($error)) {
                $this->ledger->finalizeUnknown((string) $claimed->id, $claimed->claim_token, $error);
                $this->metrics->record(BalanceOperationMetric::OutboundDeliveryOutcome, 'unknown');
            } else {
                $this->ledger->finalizeFailed(
                    (string) $claimed->id,
                    $claimed->claim_token,
                    $error,
                    $this->ledger->retryAfterSeconds($error),
                );
                $this->metrics->record(BalanceOperationMetric::OutboundDeliveryOutcome, 'failed');
            }
        }
    }
}
