<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendStoreBalanceTopUpInstructions;
use App\Jobs\SendStoreBalanceTopUpReceipt;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\StoreBalanceTopUpOutboundOutcome;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpOutboundTest extends TelegramBotTestSupport
{
    public function test_known_payable_top_up_materializes_and_hands_off_one_instruction_effect(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp();

        $result = app(StoreBalanceTopUpOutbound::class)->handoffInstructions($topUp);

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::Queued, $result->outcome);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame(TelegramOutboundEffectType::BalanceTopUpInstructions->value, TelegramOutboundEffect::query()->sole()->effect_type);
        Queue::assertPushed(SendStoreBalanceTopUpInstructions::class);
    }

    public function test_known_paid_settled_top_up_materializes_and_hands_off_one_receipt_effect(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);

        $result = app(StoreBalanceTopUpOutbound::class)->handoffReceipt($topUp);

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::Queued, $result->outcome);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame(TelegramOutboundEffectType::BalanceTopUpReceipt->value, TelegramOutboundEffect::query()->sole()->effect_type);
        Queue::assertPushed(SendStoreBalanceTopUpReceipt::class);
    }

    public function test_non_payable_top_up_is_not_handed_off(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp(['status' => StoreBalanceTopUpStatus::Pending]);

        $result = app(StoreBalanceTopUpOutbound::class)->handoffInstructions($topUp);

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::NotEligible, $result->outcome);
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        Queue::assertNotPushed(SendStoreBalanceTopUpInstructions::class);
    }

    public function test_repeated_instruction_handoff_reuses_one_deterministic_effect(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp();
        $outbound = app(StoreBalanceTopUpOutbound::class);

        $first = $outbound->handoffInstructions($topUp);
        $second = $outbound->handoffInstructions($topUp->fresh());

        $this->assertSame($first->outcome, $second->outcome);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
    }

    public function test_missing_instruction_and_receipt_effects_are_discovered_once(): void
    {
        Queue::fake();
        $instructionTopUp = $this->makeTopUp(['provider' => ' KLIKQRIS ']);
        $receiptTopUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);

        $first = app(StoreBalanceTopUpOutbound::class)->recoverMissing();
        $second = app(StoreBalanceTopUpOutbound::class)->recoverMissing();

        $this->assertSame(2, $first);
        $this->assertSame(0, $second);
        $this->assertSame(2, TelegramOutboundEffect::query()->count());
        $this->assertDatabaseHas('telegram_outbound_effects', [
            'source_id' => (string) $instructionTopUp->getKey(),
            'effect_type' => TelegramOutboundEffectType::BalanceTopUpInstructions->value,
        ]);
        $this->assertDatabaseHas('telegram_outbound_effects', [
            'source_id' => (string) $receiptTopUp->getKey(),
            'effect_type' => TelegramOutboundEffectType::BalanceTopUpReceipt->value,
        ]);
        Queue::assertPushed(SendStoreBalanceTopUpInstructions::class);
        Queue::assertPushed(SendStoreBalanceTopUpReceipt::class);
    }

    public function test_ambiguous_instruction_effect_is_left_for_review_without_a_blind_handoff(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp();
        $outbound = app(StoreBalanceTopUpOutbound::class);
        $outbound->handoffInstructions($topUp);
        $effect = TelegramOutboundEffect::query()->sole();
        $effect->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now(),
            'last_error_code' => 'telegram_transport_ambiguous',
        ])->save();
        Queue::fake();

        $result = $outbound->handoffInstructions($topUp->fresh());

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::ReviewRequired, $result->outcome);
        $this->assertSame(TelegramOutboundEffectState::Unknown, TelegramOutboundEffect::query()->sole()->state);
        Queue::assertNotPushed(SendStoreBalanceTopUpInstructions::class);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create(['locale' => 'en']);
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => 'confirmed',
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create(array_replace([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => 'klikqris',
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'top-up-'.$merchantRef,
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => 'provider-reference',
            'provider_payload' => [
                'data' => [
                    'order_id' => $merchantRef,
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'status' => 'PENDING',
                ],
            ],
            'provider_qris_url' => 'https://klikqris.com/storage/sandbox/qris.png',
            'provider_fee' => 16,
            'provider_total_payment' => 5016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => 'provider-signature',
            'provider_create_state' => 'created',
            'provider_create_attempted_at' => now()->subMinutes(2),
        ], $overrides));
    }
}
