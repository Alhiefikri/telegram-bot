<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TelegramPaymentProofIntake
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramManualPaymentIntents $orders,
        private readonly TelegramManualTopUpIntents $topUps,
    ) {}

    public function receive(Customer $customer, string $chatId, array $message, ?string $reference = null): bool
    {
        if ($chatId !== (string) $customer->telegram_id) {
            return false;
        }
        $hasPhoto = is_array($message['photo'] ?? null) && $message['photo'] !== [];
        if ($reference === null && ! $hasPhoto) {
            return false;
        }
        $legacyPhotoReply = $reference !== null
            && isset($message['reply_to_message']['photo'])
            && ($message['reply_to_message']['from']['is_bot'] ?? false) !== true;

        $target = null;
        if ($reference !== null && $reference !== '') {
            if (preg_match('/^topup:([1-9][0-9]*)$/D', $reference, $matches)) {
                $target = $this->topUp($customer, $matches[1]);
            } else {
                $target = $customer->orders()->where('invoice_number', $reference)->first();
            }
            if ($target === null) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.invoice_not_found'));

                return true;
            }
        } elseif (isset($message['reply_to_message']['message_id'])) {
            $target = $this->replyTarget($customer, $chatId, (int) $message['reply_to_message']['message_id']);
            // A caption command replying to a buyer's photo is the legacy flow.
            if ($target === null && ! $legacyPhotoReply) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.reply_unavailable'));

                return true;
            }
        }

        if ($target === null) {
            $candidates = $this->candidates($customer);
            if ($candidates->count() > 1) {
                $keyboard = $candidates->map(fn ($candidate) => [[
                    'text' => $candidate instanceof Order
                        ? $candidate->invoice_number
                        : $this->locale->translate($customer, 'payment.proof.topup_option', ['id' => $candidate->id, 'amount' => $this->locale->idr((int) $candidate->amount)]),
                    'callback_data' => 'proof:'.($candidate instanceof Order ? 'order:' : 'topup:').$candidate->getKey(),
                    'style' => 'primary',
                ]])->all();
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.choose'), ['inline_keyboard' => $keyboard]);

                return true;
            }
            $target = $candidates->first();
        }

        if ($target === null) {
            if ($reference !== null) {
                $this->orders->submitManualPaymentProof($customer, $chatId, $message, '');

                return true;
            }

            return false;
        }
        if (! $hasPhoto && (! $legacyPhotoReply || $this->replyTarget($customer, $chatId, (int) ($message['reply_to_message']['message_id'] ?? 0)) !== null)) {
            $this->prompt($customer, $chatId, $target);

            return true;
        }
        if ($target instanceof StoreBalanceTopUp) {
            $this->topUps->submitTopUpProof($customer, $chatId, $message, (int) $target->getKey());
        } else {
            $this->orders->submitManualPaymentProof($customer, $chatId, $message, $target->invoice_number);
        }

        return true;
    }

    public function choose(TelegramCallbackContext $context, string $type, string $id): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        if ($context->chatId !== (string) $context->customer->telegram_id) {
            return;
        }
        $target = $type === 'order'
            ? $context->customer->orders()->whereKey($id)->first()
            : $this->topUp($context->customer, $id);
        if ($target === null || ! $this->candidates($context->customer)->contains(fn ($candidate) => get_class($candidate) === get_class($target) && (string) $candidate->getKey() === (string) $target->getKey())) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.proof.reply_unavailable'));

            return;
        }
        $this->prompt($context->customer, $context->chatId, $target);
    }

    public function bindOrderPrompt(Customer $customer, string $chatId, int $messageId, Order $order): void
    {
        $this->bind($customer, $chatId, $messageId, $order);
    }

    private function prompt(Customer $customer, string $chatId, Order|StoreBalanceTopUp $target): void
    {
        $response = $this->bot->sendMessage($chatId, $this->locale->translate($customer,
            $target instanceof Order ? 'payment.proof.prompt_order' : 'payment.proof.prompt_topup',
            ['invoice' => $target instanceof Order ? htmlspecialchars($target->invoice_number, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '', 'id' => $target->getKey()],
        ), ['force_reply' => true, 'selective' => true], ['parse_mode' => 'HTML']);
        $messageId = $response['result']['message_id'] ?? null;
        if (is_int($messageId) && $messageId > 0) {
            $this->bind($customer, $chatId, $messageId, $target);
        }
    }

    private function bind(Customer $customer, string $chatId, int $messageId, Order|StoreBalanceTopUp $target): void
    {
        if ($messageId <= 0 || $chatId !== (string) $customer->telegram_id || (int) $target->customer_id !== (int) $customer->getKey()) {
            return;
        }
        DB::table('telegram_payment_proof_prompts')->updateOrInsert(['chat_id' => $chatId, 'message_id' => $messageId], [
            'customer_id' => $customer->getKey(),
            'target_type' => $target instanceof Order ? 'order' : 'topup',
            'target_id' => (string) $target->getKey(),
            'created_at' => now(),
        ]);
    }

    private function replyTarget(Customer $customer, string $chatId, int $messageId): Order|StoreBalanceTopUp|null
    {
        $prompt = DB::table('telegram_payment_proof_prompts')->where('customer_id', $customer->getKey())
            ->where('chat_id', $chatId)->where('message_id', $messageId)->first();
        if ($prompt !== null) {
            return $prompt->target_type === 'order'
                ? $customer->orders()->whereKey($prompt->target_id)->first()
                : $this->topUp($customer, $prompt->target_id);
        }
        $effect = TelegramOutboundEffect::query()->where('target_type', 'customer')
            ->where('telegram_chat_id', $chatId)->where('telegram_message_id', $messageId)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->whereIn('effect_type', [TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT, TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP])
            ->latest('created_at')->first();
        if ($effect !== null) {
            return $effect->source_type === Order::class
                ? $customer->orders()->whereKey($effect->source_id)->first()
                : $this->topUp($customer, $effect->source_id);
        }

        return StoreBalanceTopUp::query()->where('customer_id', $customer->getKey())
            ->where('chat_id', $chatId)->where('canonical_message_id', $messageId)->first();
    }

    private function topUp(Customer $customer, string $id): ?StoreBalanceTopUp
    {
        return StoreBalanceTopUp::query()->where('customer_id', $customer->getKey())->where('chat_id', (string) $customer->telegram_id)->whereKey($id)->first();
    }

    private function candidates(Customer $customer): Collection
    {
        $orders = $customer->orders()->where('order_status', OrderStatus::PendingPayment)
            ->where('payment_status', PaymentStatus::Pending)
            ->whereHas('payments', fn ($query) => $query->where('provider', 'manual')->where('status', PaymentStatus::Pending)
                ->whereNotIn('method', ['USDT_BEP20'])->whereNull('payment_proof_submitted_at'))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get();
        $topUps = StoreBalanceTopUp::query()->where('customer_id', $customer->getKey())
            ->where('chat_id', (string) $customer->telegram_id)->where('status', StoreBalanceTopUpStatus::AwaitingConfirmation->value)
            ->where(fn ($query) => $query->where('provider', 'manual')->orWhereNotNull('manual_alternative_at'))
            ->whereNull('payment_proof_submitted_at')->where('expires_at', '>', now())->get();

        return $orders->toBase()->concat($topUps);
    }
}
