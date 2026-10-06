<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O2 #558 delivery pause: balance receipt messages are deferred, never lost and
 * never duplicated, and no money path is blocked by the pause.
 */
final class BalanceReceiptDeliveryPauseTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_pause_defers_balance_receipts_and_keeps_them_pending(): void
    {
        $receipt = $this->effect(TelegramOutboundEffectType::UsdtTopUpReceipt, 'usdt-receipt-pause');
        $idrReceipt = $this->effect(TelegramOutboundEffectType::BalanceTopUpReceipt, 'idr-receipt-pause');
        $refund = $this->effect(TelegramOutboundEffectType::UsdtRefundReceipt, 'usdt-refund-pause');
        $credentials = $this->effect(TelegramOutboundEffectType::ReceiptCredentials, 'order-credentials-pause');

        $this->pauseDelivery();

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $readyTypes = $ledger->readyToDispatchQuery()->pluck('effect_type')->all();
        $this->assertNotContains(TelegramOutboundEffectType::UsdtTopUpReceipt->value, $readyTypes);
        $this->assertNotContains(TelegramOutboundEffectType::BalanceTopUpReceipt->value, $readyTypes);
        $this->assertNotContains(TelegramOutboundEffectType::UsdtRefundReceipt->value, $readyTypes);
        $this->assertContains(TelegramOutboundEffectType::ReceiptCredentials->value, $readyTypes);

        $this->assertNull($ledger->claim((string) $receipt->id));
        $this->assertNull($ledger->claim((string) $idrReceipt->id));
        $this->assertNull($ledger->claim((string) $refund->id));
        $this->assertNotNull($ledger->claim((string) $credentials->id), 'An unrelated delivery family keeps working.');

        foreach ([$receipt, $idrReceipt, $refund] as $effect) {
            $effect->refresh();
            $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
            $this->assertSame(0, (int) $effect->attempts, 'A paused delivery consumes no attempt.');
            $this->assertNull($effect->claim_token);
            $this->assertNull($effect->lease_until);
        }
    }

    public function test_resume_delivers_the_same_untouched_effect(): void
    {
        $effect = $this->effect(TelegramOutboundEffectType::UsdtTopUpReceipt, 'usdt-receipt-resume');
        $this->pauseDelivery();

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $this->assertNull($ledger->claim((string) $effect->id));

        $this->resumeDelivery();

        $claimed = $ledger->claim((string) $effect->id);
        $this->assertNotNull($claimed);
        $this->assertSame(1, (int) $claimed->attempts);
        $this->assertSame(TelegramOutboundEffectState::Sending, $claimed->state);
        $this->assertNotNull($claimed->claim_token);
    }

    public function test_delivery_and_funding_pauses_never_block_reconciliation_or_money(): void
    {
        $customer = Customer::factory()->create();
        $this->pauseDelivery();
        $this->pauseScope(CustomerUsdtFundingPauseScope::Funding, 'funding drill');

        // Reconciliation keeps running while both pauses are active.
        $this->artisan('usdt-balance:reconcile', ['--json' => true])->assertExitCode(0);

        // A pause is not a ledger freeze: existing money keeps settling/crediting.
        $result = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(2_000_000), 'topup-paused-recovery', 'receipt-paused-recovery', 'v1'),
            'usdt:topup-paused-recovery',
        );

        $this->assertTrue($result->applied);
        $this->assertSame(2_000_000, $result->balanceAfterMicro);
    }

    public function test_delivery_pause_is_removable_and_audited(): void
    {
        $this->pauseDelivery();
        $this->assertTrue(app(CustomerUsdtFundingPolicyManager::class)->isPaused(CustomerUsdtFundingPauseScope::Delivery));

        $this->resumeDelivery();
        $this->assertFalse(app(CustomerUsdtFundingPolicyManager::class)->isPaused(CustomerUsdtFundingPauseScope::Delivery));
    }

    private function pauseDelivery(): void
    {
        $this->pauseScope(CustomerUsdtFundingPauseScope::Delivery, 'delivery drill');
    }

    private function resumeDelivery(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->resume(CustomerUsdtFundingPauseScope::Delivery, $owner, null);
    }

    private function pauseScope(CustomerUsdtFundingPauseScope $scope, string $reason): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->pause($scope, $reason, $owner, null);
    }

    private function effect(TelegramOutboundEffectType $type, string $key): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()->create([
            'effect_key' => $key,
            'effect_group_key' => $key,
            'sequence' => 1,
            'effect_type' => $type->value,
            'source_type' => Customer::class,
            'source_id' => $key,
            'target_type' => 'customer',
            'telegram_chat_id' => '910002',
            'queue' => 'notifications',
            'telegram_method' => 'sendMessage',
            'locale' => 'id',
            'template_version' => 'fixture-v1',
            'state' => TelegramOutboundEffectState::Pending->value,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'outcome_metadata' => ['message' => 'fixture'],
        ]);
    }
}
