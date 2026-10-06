<?php

namespace Tests\Support;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\OrderRefund;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\WarrantyClaim;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpSettlementEvidence;
use Illuminate\Support\Str;

trait BuildsBalanceAdjustmentSources
{
    use BuildsBuyMailWarrantyFixture { fixture as warrantyFixture; }

    protected function adjustmentTopUp(Customer $customer): StoreBalanceTopUp
    {
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id, 'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1, 'policy_version' => 1, 'amount' => 5000, 'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed, 'expires_at' => now()->addMinutes(10), 'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create([
            'customer_id' => $customer->id, 'store_balance_funding_intent_id' => $intent->id,
            'chat_id' => (string) $customer->telegram_id, 'canonical_message_id' => 1, 'locale' => 'en',
            'provider' => 'klikqris', 'merchant_ref' => 'BALANCE-I1-'.Str::random(12),
            'idempotency_key' => 'adjustment-fixture:'.$intent->id, 'currency' => 'IDR', 'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::Expired, 'provider_reference' => 'TRANSFER-I1-'.$intent->id,
            'provider_fee' => 0, 'provider_total_payment' => 5000, 'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(20), 'provider_expires_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinutes(10), 'provider_create_state' => 'provider_expired',
        ]);
    }

    protected function adjustmentRefund(): array
    {
        [$customer, $unit] = $this->warrantyFixture();
        $claim = WarrantyClaim::create([
            'public_id' => (string) Str::uuid(), 'supplier_account_unit_id' => $unit->id,
            'customer_id' => $customer->id, 'order_id' => $unit->order_id, 'proof_path' => 'fixture.enc',
            'proof_digest' => str_repeat('a', 64), 'note' => 'Verified claim', 'state' => 'approved',
            'verified_at' => now(), 'refund_deadline' => now()->addHour(), 'settlement_state' => 'refund_pending', 'settlement_choice' => 'refund',
        ]);
        $refund = OrderRefund::create([
            'order_id' => $unit->order_id, 'payment_id' => $unit->order->payments()->sole()->id, 'customer_id' => $customer->id,
            'supplier_account_unit_id' => $unit->id, 'warranty_claim_id' => $claim->id, 'amount' => 8000, 'currency' => 'IDR', 'state' => 'requested',
        ]);

        return [$customer, $unit, $claim, $refund];
    }

    protected function observeAdjustmentTopUp(StoreBalanceTopUp $topUp): void
    {
        $evidence = new StoreBalanceTopUpSettlementEvidence(
            'klikqris', $topUp->merchant_ref, $topUp->provider_reference, 'SUCCESS', 5000, 5000, true, 0);
        app(StoreBalanceTopUpService::class)->settleVerifiedEvidence($topUp, $evidence,
            fn ($locked) => StoreBalanceTopUpEvent::create([
                'store_balance_top_up_id' => $locked->id, 'provider' => 'klikqris', 'event_type' => 'reconciliation',
                'merchant_ref' => $locked->merchant_ref, 'provider_reference' => $locked->provider_reference,
                'provider_status' => 'SUCCESS', 'raw_payload' => [], 'idempotency_key' => 'race:'.Str::uuid(),
                'received_at' => now(), 'last_received_at' => now(), 'received_count' => 1,
            ]));
    }
}
