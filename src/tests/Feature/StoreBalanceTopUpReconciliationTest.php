<?php

namespace Tests\Feature;

use App\Enums\KlikQrisEnvironment;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisTopUpLifecycleResult;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpReconciliationTest extends TelegramBotTestSupport
{
    private const QRIS_URL = 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png';

    public function test_scheduled_reconciliation_recovers_paid_top_up_once_and_reports_safe_row_data(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'SUCCESS',
            'paid_at' => now()->subMinute()->toISOString(),
            'expired_at' => $expiresAt->toISOString(),
            'signature' => 'top-up-signature',
        ]);

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('recovered=1')
            ->assertSuccessful();

        $topUp->refresh();
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(StoreBalanceEntryKind::TopUpCredit, StoreBalanceEntry::query()->sole()->kind);
        $this->assertSame(5000, StoreBalanceEntry::query()->sole()->amount);
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertNotNull(StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->sole()->processed_at);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('eligible=0')
            ->assertSuccessful();

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertStringNotContainsString('top-up-signature', json_encode(StoreBalanceTopUpEvent::query()->sole()->raw_payload, JSON_THROW_ON_ERROR));
        $surface = app(TelegramMenuIntents::class)->fundingTopUpSurface(
            $topUp->fresh(['customer'])->customer,
            $topUp->fresh(),
            KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult),
        );
        $this->assertStringContainsString('delayed payment was recovered', strtolower($surface->caption));
    }

    public function test_pending_provider_status_keeps_top_up_unpaid_and_requeues_the_existing_instruction(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'PENDING',
            'expired_at' => $expiresAt->toISOString(),
            'signature' => 'top-up-signature',
        ]);

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('pending=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(
            'balance-top-up:'.$topUp->getKey().':klikqris-instructions:v1',
            $effect->effect_key,
        );

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('pending=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame($effect->getKey(), TelegramOutboundEffect::query()->sole()->getKey());
    }

    public function test_telegram_handoff_failure_does_not_fail_financial_reconciliation(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'PENDING',
            'expired_at' => $expiresAt->toISOString(),
            'signature' => 'top-up-signature',
        ]);

        $outboundEffects = Mockery::mock(TelegramOutboundEffectService::class);
        $outboundEffects->shouldReceive('forBalanceTopUp')
            ->andReturnNull();
        $outboundEffects->shouldReceive('recordBalanceTopUpInstructions')
            ->andThrow(new \RuntimeException('Telegram handoff unavailable.'));
        $this->app->instance(TelegramOutboundEffectService::class, $outboundEffects);
        Log::spy();

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('pending=1')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'klikqris.balance_top_up_reconciliation.outbound_handoff_failed',
                Mockery::on(static function (array $context) use ($topUp): bool {
                    return ($context['top_up_id'] ?? null) === (string) $topUp->getKey()
                        && ($context['merchant_ref_hash'] ?? null) === hash('sha256', $topUp->merchant_ref)
                        && ! array_key_exists('merchant_ref', $context);
                }),
            );

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    public function test_expired_provider_status_never_credits_the_requested_balance(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'EXPIRED',
            'expired_at' => $expiresAt->toISOString(),
        ]);

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('expired=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::Expired, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    public function test_success_observed_after_local_deadline_expires_without_crediting_balance(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'SUCCESS',
            'paid_at' => $expiresAt->copy()->subMinute()->toISOString(),
            'expired_at' => $expiresAt->toISOString(),
            'signature' => 'top-up-signature',
        ]);

        $this->travelTo($expiresAt->copy()->addSecond());
        $result = app(StoreBalanceTopUpService::class)->reconcile($topUp);

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        $this->assertSame(StoreBalanceTopUpStatus::Expired, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    public function test_conflicting_paid_evidence_moves_top_up_to_explicit_manual_review_without_credit(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        $this->fakeStatus($topUp, [
            'status' => 'SUCCESS',
            'amount' => 6000,
            'paid_at' => now()->subMinute()->toISOString(),
            'expired_at' => $expiresAt->toISOString(),
            'signature' => 'top-up-signature',
        ]);

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('review=1')
            ->assertSuccessful();

        $topUp->refresh();
        $this->assertSame(StoreBalanceTopUpStatus::InvalidProviderResult, $topUp->status);
        $this->assertSame('reconciliation_review', $topUp->provider_create_state);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $review = StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation_review')->sole();
        $this->assertSame('manual_review', $review->raw_payload['outcome']);
        $this->assertStringNotContainsString('top-up-signature', json_encode($review->raw_payload, JSON_THROW_ON_ERROR));
    }

    public function test_provider_transport_failure_leaves_top_up_retryable_without_mutating_balance(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.provider_verification.reconciliation.max_attempts', 1);
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $topUp = $this->makeTopUp([
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
        ]);
        Http::fake(function () {
            return Http::failedConnection();
        });

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('pending=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::RecoveryPending, $topUp->fresh()->status);
        $this->assertSame('recovery_unconfirmed', $topUp->fresh()->provider_create_state);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_late_success_reconciliation_after_compensation_records_evidence_without_credit_or_paid_status(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => 'provider-late-reference',
            'provider_create_state' => 'provider_expired',
        ]);

        app(StoreBalanceOwnerCompensationService::class)->release(
            topUpId: $topUp->id,
            actor: $owner,
            providerReference: 'manual-resolution-reference',
            reason: 'Late provider evidence must not reopen a resolved top-up.',
        );

        $transaction = KlikQrisTransaction::fromStatusPayload([
            'order_id' => $topUp->merchant_ref,
            'amount' => (int) $topUp->amount,
            'total_amount' => (int) $topUp->provider_total_payment,
            'status' => 'SUCCESS',
            'fee' => (int) $topUp->provider_fee,
            'paid_at' => now()->subMinute()->toISOString(),
            'expired_at' => $topUp->provider_expires_at->toISOString(),
            'signature' => 'top-up-signature',
            'provider_reference' => 'provider-late-reference',
        ], KlikQrisEnvironment::Sandbox);
        $event = app(StoreBalanceTopUpService::class)->settleAuthoritativeTransaction(
            $topUp->fresh(),
            $transaction,
            fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => app(StoreBalanceTopUpEventLogger::class)->logReconciliation($transaction, $lockedTopUp),
        );

        $this->assertNotNull($event->processed_at);
        $this->assertSame(StoreBalanceTopUpStatus::Compensated, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(StoreBalanceEntryKind::OwnerCompensationCredit, StoreBalanceEntry::query()->sole()->kind);
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->whereNotNull('processed_at')->count());
    }

    public function test_compensated_top_up_expiration_is_terminal(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => 'provider-expired-reference',
            'provider_create_state' => 'provider_expired',
        ]);

        app(StoreBalanceOwnerCompensationService::class)->release(
            topUpId: $topUp->id,
            actor: $owner,
            providerReference: 'manual-resolution-reference',
            reason: 'A compensated top-up must remain terminal.',
        );

        $topUp->forceFill([
            'expires_at' => now()->subMinute(),
            'provider_expires_at' => now()->subMinute(),
        ])->save();

        $this->assertFalse(app(StoreBalanceTopUpService::class)->expireIfDue($topUp->fresh()));
        $this->assertSame(StoreBalanceTopUpStatus::Compensated, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(StoreBalanceEntryKind::OwnerCompensationCredit, StoreBalanceEntry::query()->sole()->kind);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create(['locale' => 'en']);
        $chatId = (string) $customer->telegram_id;
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => $chatId,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create(array_replace([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => $chatId,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => 'klikqris',
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'top-up-'.$merchantRef,
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => null,
            'provider_payload' => [
                'data' => [
                    'order_id' => $merchantRef,
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'status' => 'PENDING',
                ],
            ],
            'provider_qris_url' => self::QRIS_URL,
            'provider_fee' => 16,
            'provider_total_payment' => 5016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => 'top-up-signature',
            'provider_create_state' => 'created',
            'provider_create_attempted_at' => now()->subMinutes(2),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function fakeStatus(StoreBalanceTopUp $topUp, array $overrides): void
    {
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/status/*' => function () use ($topUp, $overrides) {
                return Http::response([
                    'data' => array_replace([
                        'order_id' => $topUp->merchant_ref,
                        'amount' => 5000,
                        'total_amount' => 5016,
                        'status' => 'PENDING',
                        'expired_at' => $topUp->provider_expires_at->toISOString(),
                        'signature' => 'top-up-signature',
                    ], $overrides),
                ]);
            },
        ]);
    }
}
