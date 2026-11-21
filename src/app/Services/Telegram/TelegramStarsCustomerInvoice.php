<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\TelegramStars\StarsPricingPolicyManager;
use App\Services\TelegramStars\TelegramStarsChargeIdentity;
use App\Services\TelegramStars\TelegramStarsInvoiceTarget;
use App\Services\TelegramStars\TelegramStarsPreCheckout;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final readonly class TelegramStarsCustomerInvoice
{
    public function __construct(private StarsPricingPolicyManager $policies) {}

    public static function tender(Payment $payment): string
    {
        return 'Telegram Stars · '.Money::xtr((int) $payment->amount);
    }

    /** Read-only recovery: point to the original native invoice without forwarding or resending it. */
    public function pending(Customer $customer, Order $order, Payment $payment): array
    {
        $target = $payment->telegramStarsDetail;
        if ($target === null || ! TelegramStarsInvoiceTarget::isPending($target, $order, $payment)
            || $target->customer_id !== (int) $customer->getKey() || $target->chat_id !== $customer->telegram_id) {
            return ['key' => 'stars.invoice_unknown'];
        }

        $approvedAt = DB::table('telegram_stars_approvals')->where('payment_id', $payment->id)->value('approved_at_epoch');
        if ($approvedAt !== null) {
            return ['key' => (int) $approvedAt + TelegramStarsPreCheckout::GRACE_SECONDS > now()->timestamp
                ? 'stars.verifying' : 'stars.invoice_unknown'];
        }
        if ($target->deadline_epoch <= now()->timestamp) {
            return ['key' => 'stars.invoice_expired'];
        }
        if (! $this->policies->current()->issuanceEnabled) {
            return ['key' => 'stars.invoice_paused'];
        }

        $namespace = TelegramStarsChargeIdentity::configuredNamespace();
        if ($namespace === null) {
            return ['key' => 'stars.invoice_unknown'];
        }
        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::TelegramStarsInvoice->value)
            ->where('source_type', Payment::class)->where('source_id', $payment->id)
            ->where('telegram_chat_id', (string) $target->chat_id)
            ->where('outcome_metadata->stars_bot_namespace', $namespace);
        $id = (clone $effects)->where('state', TelegramOutboundEffectState::Sent->value)
            ->whereNotNull('telegram_message_id')->whereNull('telegram_message_deleted_at')
            ->orderByDesc('sequence')->value('telegram_message_id');
        if ((int) $id > 0) {
            return [
                'key' => 'stars.invoice_ready',
                'options' => ['reply_parameters' => ['message_id' => (int) $id, 'allow_sending_without_reply' => true]],
            ];
        }

        $latest = $effects->orderByDesc('sequence')->first();

        return ['key' => in_array($latest?->state, [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Sending], true)
            ? 'stars.invoice_pending' : 'stars.invoice_unknown'];
    }
}
