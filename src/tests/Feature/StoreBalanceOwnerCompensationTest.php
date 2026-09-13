<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Jobs\SendStoreBalanceOwnerCompensationNotification;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\StoreBalanceCompensation;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres-release')]
final class StoreBalanceOwnerCompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_only_queue_hides_provider_secrets_and_release_route_rejects_non_owner(): void
    {
        config()->set('services.telegram.token', '123456:owner-compensation-secret');
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::InvalidProviderResult,
            'provider_create_last_error' => 'Provider response contained 123456:owner-compensation-secret and a QRIS payload.',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($admin)
            ->post(route('admin.store-balance.top-ups.compensate', $topUp), [
                'provider_reference' => 'PROVIDER-ADMIN-REJECTED',
                'reason' => 'Admin must not release compensation.',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('admin.dashboard', ['tab' => StoreBalanceOwnerCompensationService::TAB]))
            ->assertOk()
            ->assertSee('Kompensasi Top-up')
            ->assertSee((string) $topUp->customer->telegram_id)
            ->assertSee('Hasil provider invalid')
            ->assertDontSee('owner-compensation-secret')
            ->assertDontSee($topUp->provider_qris_url);
    }

    public function test_owner_release_uses_original_amount_records_balance_transition_and_materializes_one_notification(): void
    {
        Queue::fake();
        config()->set('services.telegram.token', '123456:owner-compensation-secret');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp([
            'amount' => 7500,
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => 'KLIK-EXPIRED-7500',
        ]);
        app(StoreBalanceLedger::class)->credit(
            $topUp->customer,
            1000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'seed-'.$topUp->id,
            'seed:'.$topUp->id,
        );

        $this->actingAs($owner)
            ->post(route('admin.store-balance.top-ups.compensate', $topUp), [
                'amount' => 1,
                'customer_id' => 999999,
                'provider_reference' => 'MANUAL-REF-7500',
                'reason' => 'Provider tidak dapat memastikan status transaksi: 123456:owner-compensation-secret.',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => StoreBalanceOwnerCompensationService::TAB]).'#admin-section-balance-issues');

        $topUp->refresh();
        $compensation = StoreBalanceCompensation::query()->with('ledgerEntry')->sole();
        $entry = StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::OwnerCompensationCredit)->sole();

        $this->assertSame(StoreBalanceTopUpStatus::Compensated, $topUp->status);
        $this->assertSame(7500, $compensation->amount);
        $this->assertStringNotContainsString('owner-compensation-secret', $compensation->reason);
        $audit = AuditLog::query()->where('action', 'store_balance.top_up.compensated')->sole();
        $this->assertStringNotContainsString('owner-compensation-secret', (string) json_encode($audit->new_values, JSON_THROW_ON_ERROR));
        $this->assertSame(7500, $entry->amount);
        $this->assertSame(StoreBalanceEntryKind::OwnerCompensationCredit, $entry->kind);
        $this->assertSame('owner_compensation', $entry->source_type);
        $this->assertSame(StoreBalanceOwnerCompensationService::compensationSourceId($topUp->id), $entry->source_id);
        $this->assertSame(1000, $entry->balance_after - $entry->amount);
        $this->assertSame(8500, $entry->balance_after);
        $this->assertSame($entry->id, $compensation->ledger_entry_id);
        $activityItem = collect(app(StoreBalanceActivityService::class)->firstPage($topUp->customer->fresh())->items)
            ->first(fn ($item): bool => $item->type === 'top_up' && $item->reference === '#'.$topUp->id);
        $this->assertSame('compensation', $activityItem->kind);
        $this->assertSame('compensated', $activityItem->state);
        $this->assertSame(7500, $activityItem->signedAmount);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'store_balance.top_up.compensated',
            'subject_type' => StoreBalanceTopUp::class,
            'subject_id' => $topUp->id,
            'user_id' => $owner->id,
        ]);

        $effect = TelegramOutboundEffect::query()
            ->where('source_type', StoreBalanceCompensation::class)
            ->where('source_id', (string) $compensation->id)
            ->sole();
        $this->assertSame(TelegramOutboundEffectType::OwnerCompensationNotification->value, $effect->effect_type);
        $this->assertSame(StoreBalanceCompensation::class, $effect->source_type);
        $this->assertSame((string) $compensation->id, $effect->source_id);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        Queue::assertPushed(
            SendStoreBalanceOwnerCompensationNotification::class,
            fn (SendStoreBalanceOwnerCompensationNotification $job): bool => $job->compensationId === (string) $compensation->id
                && $job->effectId === (string) $effect->id,
        );
    }

    public function test_compensation_notification_delivery_is_ledger_idempotent(): void
    {
        Queue::fake();
        config()->set('services.telegram.token', '123456:test-compensation-token');
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 88001],
            ]),
        ]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'amount' => 6000,
        ]);

        $this->actingAs($owner)->post(route('admin.store-balance.top-ups.compensate', $topUp), [
            'provider_reference' => 'MANUAL-NOTIFY-6000',
            'reason' => 'Notifikasi kompensasi wajib durable.',
        ])->assertRedirect();

        $compensation = StoreBalanceCompensation::query()->sole();
        $effect = TelegramOutboundEffect::query()
            ->where('source_type', StoreBalanceCompensation::class)
            ->where('source_id', (string) $compensation->id)
            ->sole();
        $job = new SendStoreBalanceOwnerCompensationNotification(
            (string) $compensation->id,
            effectId: (string) $effect->id,
        );
        $job->handle();
        $job->handle();

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('88001', (string) $effect->telegram_message_id);
        Http::assertSentCount(1);
    }

    public function test_repeated_release_replays_without_second_credit_and_resolution_is_immutable(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::RecoveryPending,
            'provider_created_at' => now()->subHour(),
        ]);
        $route = route('admin.store-balance.top-ups.compensate', $topUp);

        $this->actingAs($owner)->post($route, [
            'provider_reference' => 'FIRST-REF',
            'reason' => 'First owner resolution.',
        ])->assertRedirect();
        $this->actingAs($owner)->post($route, [
            'provider_reference' => 'SECOND-REF',
            'reason' => 'Second request must replay.',
        ])->assertRedirect();

        $compensation = StoreBalanceCompensation::query()->sole();
        $this->assertSame('FIRST-REF', $compensation->provider_reference);
        $this->assertSame('First owner resolution.', $compensation->reason);
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::OwnerCompensationCredit)->count());
        $this->assertSame((int) $topUp->amount, (int) $topUp->customer->balanceAccount()->value('balance'));
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_type', StoreBalanceCompensation::class)
            ->where('source_id', (string) $compensation->id)
            ->count());

        $this->expectException(LogicException::class);
        $compensation->update(['reason' => 'tampered']);
    }

    public function test_release_requires_provider_reference_and_reason_without_accepting_operator_amount(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $topUp = $this->makeTopUp(['amount' => 9100, 'status' => StoreBalanceTopUpStatus::Expired]);

        $this->actingAs($owner)
            ->from(route('admin.dashboard', ['tab' => StoreBalanceOwnerCompensationService::TAB]))
            ->post(route('admin.store-balance.top-ups.compensate', $topUp), [
                'amount' => 1,
                'customer_id' => 999999,
            ])
            ->assertRedirect()
            ->assertSessionHas('errors', static function ($errors): bool {
                return $errors instanceof ViewErrorBag
                    && $errors->getBag('balanceCompensation')->has('provider_reference')
                    && $errors->getBag('balanceCompensation')->has('reason');
            });

        $this->assertSame(0, StoreBalanceCompensation::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_queue_eager_loads_customer_and_balance_account_for_a_bounded_page(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        foreach (range(1, 21) as $index) {
            $this->makeTopUp([
                'created_at' => now()->subMinutes($index),
                'status' => StoreBalanceTopUpStatus::Expired,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $queue = app(StoreBalanceOwnerCompensationService::class)->queue($owner, ['balance_page' => 1]);

        $this->assertCount(StoreBalanceOwnerCompensationService::PER_PAGE, $queue['items']);
        $this->assertSame(2, $queue['pagination']['last_page']);
        $this->assertSame(0, collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "customer_balance_accounts"') && str_contains($sql, ' = ?'),
        )->count());
    }

    public function test_queue_hides_credited_top_ups_even_when_their_status_is_still_queueable(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $credited = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => 'KLIK-CREDITED-5000',
        ]);
        app(StoreBalanceLedger::class)->creditTopUp(
            $credited->customer,
            (int) $credited->amount,
            (string) $credited->getKey(),
            'balance-top-up:'.$credited->getKey().':credit',
        );
        $queueable = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => 'KLIK-EXPIRED-5000',
        ]);

        $queue = app(StoreBalanceOwnerCompensationService::class)->queue($owner, []);

        $ids = array_column($queue['items'], 'top_up_id');
        $this->assertNotContains($credited->getKey(), $ids);
        $this->assertContains($queueable->getKey(), $ids);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create([
            'telegram_id' => random_int(10000000, 99999999),
            'username' => 'balance_owner_test',
            'locale' => 'en',
        ]);
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => (int) ($overrides['amount'] ?? 5000),
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
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
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_reference' => null,
            'provider_payload' => ['data' => ['status' => 'EXPIRED']],
            'provider_qris_url' => 'https://provider.test/secret-qris.png',
            'provider_fee' => 16,
            'provider_total_payment' => 5016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => 'provider-signature-secret',
            'provider_create_state' => 'provider_expired',
            'provider_create_attempted_at' => now()->subMinutes(2),
            'provider_create_last_error' => null,
        ], $overrides));
    }
}
