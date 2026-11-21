<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Services\Checkout\TelegramStarsCheckoutIntent;
use App\Services\Checkout\TelegramStarsConfirmation;
use App\Services\TelegramStars\StarsPricingPolicy;
use App\Services\TelegramStars\StarsQuoteCalculator;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class TelegramStarsCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public string $invoicePayload;

    /** @param list<array{product_id: int, quantity: int, base_unit_idr: int, unit_idr: int, tier_minimum: ?int, subtotal_idr: int}> $retailLines */
    public function __construct(
        public TelegramStarsConfirmation $buyer,
        public StarsPricingPolicy $policy,
        public int $subtotalIdr,
        public array $retailLines,
        int $totalXtr,
        CarbonInterface $deadline,
    ) {
        parent::__construct(PaymentProvider::TELEGRAM_STARS, TelegramStarsCheckoutIntent::METHOD, 'XTR', $totalXtr, $deadline);

        if (! $policy->issuanceEnabled || $subtotalIdr < 1 || $totalXtr < 1 || $totalXtr > StarsQuoteCalculator::MAX_INVOICE_XTR) {
            throw new InvalidArgumentException('Telegram Stars payment snapshot is invalid.');
        }

        $this->invoicePayload = bin2hex(random_bytes(32));
    }

    public function persist(Payment $payment): void
    {
        PaymentTelegramStarsDetail::query()->create([
            'payment_id' => $payment->getKey(),
            'customer_id' => $this->buyer->customerId,
            'telegram_user_id' => $this->buyer->telegramUserId,
            'chat_id' => $this->buyer->chatId,
            'merchant_ref' => $payment->merchant_ref,
            'invoice_payload' => $this->invoicePayload,
            'deadline_epoch' => $this->expiresAt->getTimestamp(),
            'policy_version' => $this->policy->version,
            'idr_per_usd' => $this->policy->idrPerUsd,
            'reward_micro_usd' => $this->policy->rewardMicroUsd,
            'reserve_bps' => $this->policy->reserveBps,
            'subtotal_idr' => $this->subtotalIdr,
            'total_xtr' => $this->amount,
            'retail_lines' => $this->retailLines,
        ]);
    }
}
