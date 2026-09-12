<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Events\StoreBalanceTopUpSettled;
use App\Jobs\SendStoreBalanceTopUpInstructions;
use App\Jobs\SendStoreBalanceTopUpReceipt;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\StoreBalanceTopUpOutboundOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpOutboundTest extends TelegramBotTestSupport
{
    public function test_known_payable_top_up_materializes_and_hands_off_one_instruction_effect(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp();

        $result = app(StoreBalanceTopUpOutbound::class)->handoffInstructions($topUp);

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::Queued, $result->outcome);
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame(TelegramOutboundEffectType::BalanceTopUpInstructions->value, $effect->effect_type);
        $this->assertSame(StoreBalanceTopUp::class, $effect->source_type);
        $this->assertSame((string) $topUp->getKey(), $effect->source_id);
        $this->assertSame('balance-top-up:'.$topUp->getKey().':klikqris-instructions:v1', $effect->effect_key);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
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
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame(TelegramOutboundEffectType::BalanceTopUpReceipt->value, $effect->effect_type);
        $this->assertSame(StoreBalanceTopUp::class, $effect->source_type);
        $this->assertSame((string) $topUp->getKey(), $effect->source_id);
        $this->assertSame('balance-top-up:'.$topUp->getKey().':klikqris-receipt:v1', $effect->effect_key);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        Queue::assertPushed(SendStoreBalanceTopUpReceipt::class);
    }

    public function test_repeated_instruction_handoff_reuses_one_deterministic_effect(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp();
        $outbound = app(StoreBalanceTopUpOutbound::class);

        $first = $outbound->handoffInstructions($topUp);
        $firstEffect = TelegramOutboundEffect::query()->sole();
        $second = $outbound->handoffInstructions($topUp->fresh());
        $secondEffect = TelegramOutboundEffect::query()->sole();

        $this->assertSame($first->outcome, $second->outcome);
        $this->assertSame($firstEffect->getKey(), $secondEffect->getKey());
        $this->assertSame(
            'balance-top-up:'.$topUp->getKey().':klikqris-instructions:v1',
            $secondEffect->effect_key,
        );
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
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

    public function test_navigation_matching_is_owned_by_top_up_outbound_family(): void
    {
        Queue::fake();
        $instructionTopUp = $this->makeTopUp();
        $receiptTopUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);
        $outbound = app(StoreBalanceTopUpOutbound::class);

        $outbound->handoffInstructions($instructionTopUp);
        $instruction = TelegramOutboundEffect::query()
            ->where('source_id', (string) $instructionTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpInstructions->value)
            ->sole();
        $instruction->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_chat_id' => (string) $instructionTopUp->chat_id,
            'telegram_message_id' => 701,
        ])->save();

        $outbound->handoffReceipt($receiptTopUp);
        $receipt = TelegramOutboundEffect::query()
            ->where('source_id', (string) $receiptTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->sole();
        $receipt->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_chat_id' => (string) $receiptTopUp->chat_id,
            'telegram_message_id' => 702,
        ])->save();

        $this->assertTrue($outbound->hasSentInstructionFor($instructionTopUp, (string) $instructionTopUp->chat_id, 701));
        $this->assertFalse($outbound->hasSentInstructionFor($instructionTopUp, (string) $instructionTopUp->chat_id, 799));
        $this->assertTrue($outbound->hasSentReceiptFor($receiptTopUp->customer, (string) $receiptTopUp->chat_id, 702));
        $this->assertFalse($outbound->hasSentReceiptFor(Customer::factory()->create(), (string) $receiptTopUp->chat_id, 702));
    }

    public function test_failed_instruction_delivery_leaves_one_retryable_effect_without_resending(): void
    {
        Queue::fake();
        KlikQrisSandboxScenarios::configureReadySandbox();
        $topUp = $this->makeTopUp([
            'provider_qris_url' => 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png',
        ]);
        $outbound = app(StoreBalanceTopUpOutbound::class);
        $outbound->handoffInstructions($topUp);
        $effect = TelegramOutboundEffect::query()->sole();

        $this->fakeTelegramHttp([
            $topUp->provider_qris_url => Http::response("\x89PNG\r\n\x1A\nqris", 200, ['Content-Type' => 'image/png']),
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true]),
        ]);

        try {
            (new SendStoreBalanceTopUpInstructions(
                (string) $topUp->getKey(),
                effectId: (string) $effect->getKey(),
            ))->handle();
            $this->fail('A Telegram response without a message identifier must fail the instruction effect.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('successful message ID', $exception->getMessage());
        }

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_response_missing_message_id', $failed->last_error_code);
        $this->assertNotNull($failed->failed_at);
        $this->assertNotNull($failed->next_attempt_at);
        $this->assertNull($failed->telegram_message_id);

        Queue::fake();
        $retry = $outbound->handoffInstructions($topUp->fresh());

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::RetryScheduled, $retry->outcome);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        Queue::assertNotPushed(SendStoreBalanceTopUpInstructions::class);
    }

    public function test_ambiguous_instruction_delivery_stays_unresolved_without_a_blind_resend(): void
    {
        Queue::fake();
        KlikQrisSandboxScenarios::configureReadySandbox();
        $topUp = $this->makeTopUp([
            'provider_qris_url' => 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png',
        ]);
        $outbound = app(StoreBalanceTopUpOutbound::class);
        $outbound->handoffInstructions($topUp);
        $effect = TelegramOutboundEffect::query()->sole();
        $sendCalls = 0;

        $this->fakeTelegramHttp([
            $topUp->provider_qris_url => Http::response("\x89PNG\r\n\x1A\nqris", 200, ['Content-Type' => 'image/png']),
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCalls): never {
                $sendCalls++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
        ]);

        (new SendStoreBalanceTopUpInstructions(
            (string) $topUp->getKey(),
            effectId: (string) $effect->getKey(),
        ))->handle();

        $unknown = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $this->assertSame('telegram_transport_ambiguous', $unknown->last_error_code);
        $this->assertNotNull($unknown->unknown_at);
        $this->assertNull($unknown->next_attempt_at);
        $this->assertSame(1, $sendCalls);

        Queue::fake();
        $review = $outbound->handoffInstructions($topUp->fresh());

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::ReviewRequired, $review->outcome);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        Queue::assertNotPushed(SendStoreBalanceTopUpInstructions::class);
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

    public function test_generic_recovery_repairs_missing_instruction_and_receipt_effects_without_duplicate_rows(): void
    {
        Queue::fake();
        $instructionTopUp = $this->makeTopUp();
        $receiptTopUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $instructionTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpInstructions->value)
            ->count());
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $receiptTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());
        $this->assertSame(2, TelegramOutboundEffect::query()->count());
        Queue::assertPushed(SendStoreBalanceTopUpInstructions::class);
        Queue::assertPushed(SendStoreBalanceTopUpReceipt::class);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $instructionTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpInstructions->value)
            ->count());
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $receiptTopUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());
        $this->assertSame(2, TelegramOutboundEffect::query()->count());
    }

    public function test_generic_recovery_repairs_a_receipt_when_the_settlement_listener_handoff_is_missed(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);

        Event::fakeFor(function () use ($topUp): void {
            DB::transaction(function () use ($topUp): void {
                StoreBalanceTopUpSettled::dispatch((string) $topUp->getKey());
            });
        });

        $this->assertSame(0, TelegramOutboundEffect::query()->count());

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());
        Queue::assertPushed(SendStoreBalanceTopUpReceipt::class);
    }

    public function test_settlement_listener_defers_receipt_handoff_and_recovery_reuses_it(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'paid_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ]);

        DB::transaction(function () use ($topUp): void {
            StoreBalanceTopUpSettled::dispatch((string) $topUp->getKey());

            $this->assertSame(0, TelegramOutboundEffect::query()->count());
        });

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpReceipt->value)
            ->count());
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
