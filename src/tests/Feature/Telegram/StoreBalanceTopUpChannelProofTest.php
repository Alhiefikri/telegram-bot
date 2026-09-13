<?php

namespace Tests\Feature\Telegram;

use App\Enums\KlikQrisEnvironment;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\PublishBalanceTopUpChannelProof;
use App\Models\Customer;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\BalanceTopUpChannelProofDeliveryPipeline;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

#[Group('postgres-release')]
final class StoreBalanceTopUpChannelProofTest extends TelegramBotTestSupport
{
    private const CHANNEL_CHAT_ID = '-1001234567890';

    public function test_settled_top_up_materializes_one_channel_proof_effect(): void
    {
        Queue::fake();
        $this->enableSalesProof();
        $topUp = $this->makeTopUp([
            'provider_signature' => 'top-up-signature',
            'provider_create_state' => 'created',
        ]);
        $transaction = $this->successTransaction($topUp);

        app(StoreBalanceTopUpService::class)->settleAuthoritativeTransaction(
            $topUp,
            $transaction,
            fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => app(StoreBalanceTopUpEventLogger::class)->logReconciliation($transaction, $lockedTopUp),
        );

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::TopUpCredit)->count());

        $effect = TelegramOutboundEffect::query()
            ->where('source_type', StoreBalanceTopUp::class)
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpChannelProof->value)
            ->sole();
        $this->assertSame('store_channel', $effect->target_type);
        $this->assertSame(self::CHANNEL_CHAT_ID, $effect->telegram_chat_id);
        $this->assertSame('sendPhoto', $effect->telegram_method);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        Queue::assertPushed(PublishBalanceTopUpChannelProof::class);
    }

    public function test_channel_proof_job_sends_one_masked_invoice_photo(): void
    {
        $this->enableSalesProof();
        $this->fakeTelegramHttp();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now(),
            'paid_at' => now(),
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpChannelProof($topUp);
        $this->assertNotNull($effect);

        $job = new PublishBalanceTopUpChannelProof((string) $topUp->getKey(), effectId: (string) $effect->getKey());
        $job->handle(app(BalanceTopUpChannelProofDeliveryPipeline::class), app(TelegramOutboundEffectService::class));
        $job->handle(app(BalanceTopUpChannelProofDeliveryPipeline::class), app(TelegramOutboundEffectService::class));

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('123456', (string) $effect->telegram_message_id);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($topUp): bool {
            if (! str_ends_with($request->url(), '/sendPhoto')) {
                return false;
            }

            $caption = (string) $this->telegramRequestField($request, 'caption');

            return (string) $this->telegramRequestField($request, 'chat_id') === self::CHANNEL_CHAT_ID
                && (string) $this->telegramRequestField($request, 'photo') !== ''
                && str_contains($caption, 'BALANCE TOP-UP CONFIRMED')
                && str_contains($caption, 'Buyer: Fik***')
                && str_contains($caption, 'Top-up ID: '.$topUp->merchant_ref)
                && str_contains($caption, 'Amount paid: Rp 50.016')
                && ! str_contains($caption, (string) $topUp->customer->telegram_id);
        });
    }

    public function test_channel_proof_is_not_materialized_when_sales_proof_is_disabled(): void
    {
        $this->enableSalesProof(enabled: false);
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now(),
        ]);

        $this->assertNull(app(TelegramOutboundEffectService::class)->recordBalanceTopUpChannelProof($topUp));
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpChannelProof->value)
            ->count());
    }

    public function test_channel_proof_recovery_discovers_each_settled_top_up_once(): void
    {
        Queue::fake();
        $this->enableSalesProof();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now(),
        ]);

        $outbound = app(StoreBalanceTopUpOutbound::class);

        $this->assertSame(1, $outbound->recoverMissingChannelProofs(10));
        $this->assertSame(0, $outbound->recoverMissingChannelProofs(10));
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpChannelProof->value)
            ->count());
        Queue::assertPushed(PublishBalanceTopUpChannelProof::class);
    }

    public function test_channel_proof_recovery_skips_top_ups_settled_long_ago(): void
    {
        Queue::fake();
        $this->enableSalesProof();
        $recent = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now()->subDay(),
        ]);
        $historical = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now()->subDays(30),
        ]);

        $this->assertSame(1, app(StoreBalanceTopUpOutbound::class)->recoverMissingChannelProofs(10));
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $recent->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpChannelProof->value)
            ->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('source_id', (string) $historical->getKey())
            ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpChannelProof->value)
            ->count());
    }

    public function test_resolved_top_up_channel_proof_is_finalized_without_sending(): void
    {
        $this->enableSalesProof();
        $this->fakeTelegramHttp();
        $topUp = $this->makeTopUp([
            'status' => StoreBalanceTopUpStatus::Paid,
            'settled_at' => now(),
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpChannelProof($topUp);
        $this->assertNotNull($effect);

        $topUp->forceFill([
            'status' => StoreBalanceTopUpStatus::Expired,
            'settled_at' => null,
        ])->save();

        (new PublishBalanceTopUpChannelProof((string) $topUp->getKey(), effectId: (string) $effect->getKey()))
            ->handle(app(BalanceTopUpChannelProofDeliveryPipeline::class), app(TelegramOutboundEffectService::class));

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('balance_top_up_proof_not_eligible', $effect->last_error_code);
        $this->assertNull($effect->next_attempt_at);
        Http::assertNothingSent();
    }

    private function enableSalesProof(bool $enabled = true): void
    {
        StoreChannelSetting::query()->delete();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'channel_username' => '@lhie_store',
            'telegram_chat_id' => self::CHANNEL_CHAT_ID,
            'last_test_success' => true,
            'sales_proof_enabled' => $enabled,
        ]);
    }

    private function successTransaction(StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        return KlikQrisTransaction::fromStatusPayload([
            'order_id' => $topUp->merchant_ref,
            'amount' => (int) $topUp->amount,
            'total_amount' => (int) $topUp->provider_total_payment,
            'status' => 'SUCCESS',
            'fee' => (int) $topUp->provider_fee,
            'paid_at' => now()->toISOString(),
            'expired_at' => $topUp->provider_expires_at->toISOString(),
            'signature' => 'top-up-signature',
        ], KlikQrisEnvironment::Sandbox);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create([
            'telegram_id' => random_int(10000000, 99999999),
            'first_name' => 'Fikri',
            'username' => 'topup_channel_test',
            'locale' => 'id',
        ]);
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 50000,
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
            'amount' => 50000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => 'provider-topup-reference',
            'provider_payload' => ['data' => ['status' => 'PENDING']],
            'provider_qris_url' => 'https://provider.test/qris.png',
            'provider_fee' => 16,
            'provider_total_payment' => 50016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => null,
            'provider_create_state' => 'created',
            'provider_create_attempted_at' => now()->subMinutes(2),
            'provider_create_last_error' => null,
            'paid_at' => null,
            'settled_at' => null,
        ], $overrides));
    }
}
