<?php

namespace App\Services\TelegramStars;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final readonly class TelegramStarsInvoiceOutbound
{
    public function __construct(
        private StarsPricingPolicyManager $policy,
        private TelegramOutboundEffectLedgerProtocol $ledger,
        private TelegramOutboundResponseValidator $responses,
        private TelegramBotClient $bot,
    ) {}

    public static function hasAcknowledgedInvoice(PaymentTelegramStarsDetail $target, ?string $namespace = null): bool
    {
        return TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::TelegramStarsInvoice->value)
            ->where('source_type', Payment::class)->where('source_id', $target->payment_id)
            ->where('telegram_chat_id', (string) $target->chat_id)
            ->when($namespace !== null, fn ($query) => $query->where('outcome_metadata->stars_bot_namespace', $namespace))
            ->where('state', TelegramOutboundEffectState::Sent->value)->whereNotNull('telegram_message_id')->exists();
    }

    public function deliver(string $effectId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Stars invoice delivery requires a committed checkout.');
        }

        $effect = TelegramOutboundEffect::whereKey($effectId)
            ->where('effect_type', TelegramOutboundEffectType::TelegramStarsInvoice->value)
            ->where('source_type', Payment::class)->first();
        if ($effect === null) {
            return;
        }

        $namespace = TelegramStarsChargeIdentity::configuredNamespace();
        [$claimed, $target] = DB::transaction(function () use ($effect, $namespace): array {
            $orderId = Payment::whereKey($effect->source_id)->value('order_id');
            $order = $orderId === null ? null : Order::whereKey($orderId)->lockForUpdate()->first();
            $payment = $order === null ? null : Payment::whereKey($effect->source_id)->lockForUpdate()->first();
            $target = $payment?->telegramStarsDetail;
            $eligible = $target !== null && TelegramStarsInvoiceTarget::isPending($target, $order, $payment)
                && $target->deadline_epoch > now()->getTimestamp()
                && $namespace !== null && ($effect->outcome_metadata['stars_bot_namespace'] ?? null) === $namespace
                && (string) $target->chat_id === (string) $effect->telegram_chat_id
                && ! self::hasAcknowledgedInvoice($target);

            if ($eligible && ! $this->policy->lockForCheckout()->issuanceEnabled) {
                return [null, null];
            }

            return [$this->ledger->claim((string) $effect->id), $eligible ? $target : null];
        });
        if ($claimed === null) {
            return;
        }
        if ($target === null || $target->deadline_epoch <= now()->getTimestamp()) {
            $this->ledger->finalizeNotDeliverable($effectId, $claimed->claim_token, 'checkout_not_pending');

            return;
        }

        try {
            $messageId = $this->responses->messageIdFromResponse($this->bot->sendStarsInvoice($target), 'Telegram Stars invoice');
        } catch (Throwable $error) {
            if ($error instanceof RequestException && ! $this->ledger->isAmbiguous($error)
                && ($error->response?->json('ok') === false)) {
                $this->ledger->finalizeFailed($effectId, $claimed->claim_token, $error, $this->ledger->retryAfterSeconds($error));
            } else {
                $this->ledger->finalizeUnknown($effectId, $claimed->claim_token, $error);
            }

            return;
        }

        // If persisting the ACK fails, the lease expires to unknown, never back to pending.
        $this->ledger->finalizeSent($effectId, $claimed->claim_token, $messageId);
    }
}
