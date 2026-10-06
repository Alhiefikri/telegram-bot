<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectType;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtTopUp;
use App\Models\TelegramOutboundEffect;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Shared outbound service/protocol own persistence, claims, retries and ambiguity. */
final class UsdtTopUpOutbound
{
    public function __construct(
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responses,
    ) {}

    public function recordReceipt(CustomerUsdtTopUp $target, CustomerUsdtBalanceEntry $entry, bool $late): TelegramOutboundEffect
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('USDT receipt must commit with settlement.');
        }

        return $this->effects->recordUsdtTopUpReceipt($target, $entry, $late);
    }

    public function recover(int $limit = 25): int
    {
        $effects = $this->ledger->readyToDispatchQuery(effectType: TelegramOutboundEffectType::UsdtTopUpReceipt->value)
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        foreach ($effects as $effect) {
            $this->dispatcher->dispatch($effect);
        }

        return $effects->count();
    }

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if (! in_array($effect->effect_type, [TelegramOutboundEffectType::UsdtTopUpReceipt->value, TelegramOutboundEffectType::UsdtRefundReceipt->value], true)) {
            throw new InvalidArgumentException('Unsupported USDT receipt effect.');
        }
        $claimed = $this->ledger->claim((string) $effect->id);
        if ($claimed === null) {
            return;
        }
        try {
            $message = $claimed->outcome_metadata['message'] ?? null;
            if (! is_string($message) || $message === '') {
                $this->ledger->finalizeNotDeliverable((string) $claimed->id, $claimed->claim_token, 'usdt_receipt_missing');

                return;
            }
            $response = $this->bot->sendMessage((string) $claimed->telegram_chat_id, $message, options: ['parse_mode' => null]);
            $messageId = $this->responses->messageIdFromResponse($response, 'USDT balance receipt');
            $this->ledger->finalizeSent((string) $claimed->id, $claimed->claim_token, $messageId);
        } catch (Throwable $error) {
            if ($this->ledger->isAmbiguous($error)) {
                $this->ledger->finalizeUnknown((string) $claimed->id, $claimed->claim_token, $error);
            } else {
                $this->ledger->finalizeFailed((string) $claimed->id, $claimed->claim_token, $error, $this->ledger->retryAfterSeconds($error));
            }
        }
    }
}
