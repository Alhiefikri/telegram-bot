<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Events\StoreBalanceTopUpSettled;
use App\Jobs\SendStoreBalanceTopUpOwnerAlert;
use App\Listeners\MaterializeStoreBalanceTopUpReceipt;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

#[Group('postgres-release')]
final class StoreBalanceOwnerAlertTest extends TelegramBotTestSupport
{
    private const ADMIN_CHAT_ID = '1242433004';

    protected function setUp(): void
    {
        parent::setUp();

        // The PostgreSQL release lane can inherit committed fixtures from
        // earlier concurrency classes, so the admin target is pinned here.
        ManualPaymentSetting::query()->delete();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => self::ADMIN_CHAT_ID,
            'is_active' => true,
        ]);
        config()->set('services.telegram.admin_telegram_chat_id', self::ADMIN_CHAT_ID);
    }

    public function test_recovery_pending_top_up_materializes_one_admin_alert_and_delivers_once(): void
    {
        $this->fakeTelegramHttp();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::RecoveryPending,
            'provider_create_state' => 'recovery_unconfirmed',
            'provider_create_last_error' => 'Provider status endpoint unavailable.',
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpOwnerAlert($topUp);

        $this->assertNotNull($effect);
        $this->assertSame(TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value, $effect->effect_type);
        $this->assertSame('admin', $effect->target_type);
        $this->assertSame(self::ADMIN_CHAT_ID, $effect->telegram_chat_id);
        $this->assertSame(StoreBalanceTopUp::class, $effect->source_type);
        $this->assertSame((string) $topUp->getKey(), $effect->source_id);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);

        $job = new SendStoreBalanceTopUpOwnerAlert((string) $topUp->getKey(), effectId: (string) $effect->getKey());
        $job->handle();
        $job->handle();

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('123457', (string) $effect->telegram_message_id);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($topUp): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && $request['chat_id'] === self::ADMIN_CHAT_ID
                && str_contains((string) $request['text'], 'Top-up saldo butuh keputusan owner')
                && str_contains((string) $request['text'], '#'.$topUp->getKey())
                && str_contains((string) $request['text'], 'Kompensasi Top-up');
        });
    }

    public function test_plain_expired_top_up_without_payment_proof_is_not_alerted(): void
    {
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Expired,
            'provider_create_state' => 'provider_expired',
        ]);

        $this->assertNull(app(TelegramOutboundEffectService::class)->recordBalanceTopUpOwnerAlert($topUp));

        $this->assertSame(
            0,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value)
                ->count(),
        );
    }

    public function test_recovery_discovers_each_owner_alert_once(): void
    {
        Queue::fake();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::InvalidProviderResult,
            'provider_create_state' => 'reconciliation_review',
            'provider_create_last_error' => 'Provider evidence conflicted with the requested amount.',
        ]);

        $outbound = app(StoreBalanceTopUpOutbound::class);

        $this->assertSame(1, $outbound->recoverMissingOwnerAlerts(10));
        $this->assertSame(0, $outbound->recoverMissingOwnerAlerts(10));
        $this->assertSame(
            1,
            TelegramOutboundEffect::query()
                ->where('source_id', (string) $topUp->getKey())
                ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value)
                ->count(),
        );
        Queue::assertPushed(SendStoreBalanceTopUpOwnerAlert::class);
    }

    public function test_resolved_top_up_alert_is_finalized_without_delivery(): void
    {
        $this->fakeTelegramHttp();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::RecoveryPending,
            'provider_create_state' => 'recovery_unconfirmed',
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpOwnerAlert($topUp);
        $this->assertNotNull($effect);

        $topUp->forceFill(['status' => StoreBalanceTopUpStatus::Paid])->save();

        (new SendStoreBalanceTopUpOwnerAlert((string) $topUp->getKey(), effectId: (string) $effect->getKey()))->handle();

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('balance_top_up_owner_alert_not_eligible', $effect->last_error_code);
        $this->assertNull($effect->next_attempt_at);
        Http::assertNothingSent();
    }

    public function test_settled_top_up_notifies_only_admin_once_even_after_replayed_event(): void
    {
        $this->fakeTelegramHttp();
        Queue::fake();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'amount' => 100000,
            'provider_total_payment' => 100700,
            'provider_fee' => 700,
            'paid_at' => now(),
            'settled_at' => now(),
        ]);
        $listener = app(MaterializeStoreBalanceTopUpReceipt::class);
        $event = new StoreBalanceTopUpSettled((string) $topUp->getKey());
        $listener->handle($event);
        $listener->handle($event);

        $effects = TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', 'admin.balance_top_up_paid_notification')
            ->get();
        $this->assertCount(1, $effects);
        $effect = $effects->sole();
        $this->assertSame('admin', $effect->target_type);
        $this->assertSame(self::ADMIN_CHAT_ID, $effect->telegram_chat_id);
        $job = new SendStoreBalanceTopUpOwnerAlert((string) $topUp->getKey(), effectId: (string) $effect->getKey());
        $job->handle();
        $job->handle();

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($topUp): bool {
            $text = (string) $request['text'];

            return $request['chat_id'] === self::ADMIN_CHAT_ID
                && str_contains($text, 'Hasil topup balance')
                && str_contains($text, $topUp->merchant_ref)
                && str_contains($text, '@owner_alert_test ('.$topUp->customer->telegram_id.')')
                && str_contains($text, 'Status pembayaran: Paid')
                && str_contains($text, 'Saldo dikreditkan: Rp100.000')
                && str_contains($text, 'Total pembayaran: Rp100.700')
                && str_contains($text, 'Metode: QRIS')
                && ! str_contains($text, 'Earning:')
                && ! str_contains($text, 'provider-signature-secret')
                && ! str_contains($text, 'secret-qris');
        });
    }

    public function test_paid_notification_recovery_excludes_unsettled_and_old_top_ups(): void
    {
        Queue::fake();
        $recent = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now(),
        ]);
        $this->makeTopUp(['status' => StoreBalanceTopUpStatus::Paid]);
        $this->makeTopUp(['status' => StoreBalanceTopUpStatus::Payable]);
        $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now()->subDays(8),
        ]);

        $outbound = app(StoreBalanceTopUpOutbound::class);
        $this->assertSame(1, $outbound->recoverMissingPaidNotifications(10));
        $this->assertSame(0, $outbound->recoverMissingPaidNotifications(10));
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', 'admin.balance_top_up_paid_notification')
            ->sole();
        $this->assertSame((string) $recent->getKey(), $effect->source_id);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create([
            'telegram_id' => random_int(10000000, 99999999),
            'username' => 'owner_alert_test',
            'locale' => 'id',
        ]);
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $merchantRef = 'ALERT-'.Str::upper(Str::random(12));
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
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
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'id',
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
